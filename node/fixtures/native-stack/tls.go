package main

import (
	"bytes"
	"crypto/ecdsa"
	"crypto/elliptic"
	"crypto/rand"
	"crypto/tls"
	"crypto/x509"
	"crypto/x509/pkix"
	"encoding/pem"
	"errors"
	"flag"
	"fmt"
	"io"
	"log"
	"math/big"
	"net"
	"net/http"

	"github.com/pkg/sftp"
	"golang.org/x/crypto/ssh"
	"net/http/httputil"
	"net/url"
	"os"
	"path/filepath"
	"time"
)

func must(err error) {
	if err != nil {
		log.Fatal("native TLS fixture failed")
	}
}
func main() {
	mode := flag.String("mode", "init", "init, proxy or request")
	dir := flag.String("dir", "/fixture/tls", "private fixture directory")
	target := flag.String("target", "http://panelapp:8080", "proxy upstream/request URL")
	token := flag.String("token", "", "bearer token file")
	method := flag.String("method", "GET", "request method")
	contentType := flag.String("content-type", "application/json", "request content type")
	user := flag.String("user", "", "SFTP username")
	path := flag.String("path", "config.json", "SFTP path")
	hostkey := flag.String("hostkey", "/fixture/wings-host.pub", "trusted fixture host public key")
	flag.Parse()
	if *mode == "init" {
		must(os.MkdirAll(*dir, 0700))
		key, err := ecdsa.GenerateKey(elliptic.P256(), rand.Reader)
		must(err)
		ca := &x509.Certificate{SerialNumber: big.NewInt(1), Subject: pkix.Name{CommonName: "Disposable BPS test CA"}, NotBefore: time.Now().Add(-time.Hour), NotAfter: time.Now().Add(24 * time.Hour), IsCA: true, BasicConstraintsValid: true, KeyUsage: x509.KeyUsageCertSign | x509.KeyUsageDigitalSignature}
		der, err := x509.CreateCertificate(rand.Reader, ca, ca, &key.PublicKey, key)
		must(err)
		must(os.WriteFile(filepath.Join(*dir, "ca.crt"), pem.EncodeToMemory(&pem.Block{Type: "CERTIFICATE", Bytes: der}), 0644))
		for index, name := range []string{"panel", "wings", "wings2", "stock"} {
			leaf, err := ecdsa.GenerateKey(elliptic.P256(), rand.Reader)
			must(err)
			cert := &x509.Certificate{SerialNumber: big.NewInt(int64(index + 2)), Subject: pkix.Name{CommonName: name}, DNSNames: []string{name}, NotBefore: ca.NotBefore, NotAfter: ca.NotAfter, KeyUsage: x509.KeyUsageDigitalSignature, ExtKeyUsage: []x509.ExtKeyUsage{x509.ExtKeyUsageServerAuth}}
			der, err := x509.CreateCertificate(rand.Reader, cert, ca, &leaf.PublicKey, key)
			must(err)
			raw, err := x509.MarshalPKCS8PrivateKey(leaf)
			must(err)
			must(os.WriteFile(filepath.Join(*dir, name+".crt"), pem.EncodeToMemory(&pem.Block{Type: "CERTIFICATE", Bytes: der}), 0644))
			must(os.WriteFile(filepath.Join(*dir, name+".key"), pem.EncodeToMemory(&pem.Block{Type: "PRIVATE KEY", Bytes: raw}), 0600))
		}
		return
	}
	if *mode == "proxy" {
		upstream, err := url.Parse(*target)
		must(err)
		proxy := httputil.NewSingleHostReverseProxy(upstream)
		original := proxy.Director
		proxy.Director = func(r *http.Request) {
			original(r)
			r.Host = "panel:8443"
			r.Header.Set("X-Forwarded-Proto", "https")
			r.Header.Set("X-Forwarded-Port", "8443")
		}
		must((&http.Server{Addr: ":8443", Handler: proxy, TLSConfig: &tls.Config{MinVersion: tls.VersionTLS12}}).ListenAndServeTLS(filepath.Join(*dir, "panel.crt"), filepath.Join(*dir, "panel.key")))
		return
	}
	if *mode == "host-public" {
		private, err := os.ReadFile(*hostkey)
		must(err)
		signer, err := ssh.ParsePrivateKey(private)
		must(err)
		must(os.WriteFile("/fixture/wings-host.pub", ssh.MarshalAuthorizedKey(signer.PublicKey()), 0644))
		return
	}
	if *mode == "sftp" || *mode == "sftp-write" {
		password, err := os.ReadFile(*token)
		must(err)
		hostPublic, err := os.ReadFile(*hostkey)
		must(err)
		key, _, _, _, err := ssh.ParseAuthorizedKey(hostPublic)
		must(err)
		expected := key.Marshal()
		conf := &ssh.ClientConfig{User: *user, Auth: []ssh.AuthMethod{ssh.Password(string(password))}, Timeout: 5 * time.Second,
			HostKeyCallback: func(_ string, _ net.Addr, key ssh.PublicKey) error {
				if !bytes.Equal(expected, key.Marshal()) {
					return errors.New("untrusted fixture host")
				}
				return nil
			}}
		connection, err := ssh.Dial("tcp", *target, conf)
		must(err)
		defer connection.Close()
		client, err := sftp.NewClient(connection)
		must(err)
		defer client.Close()
		if *mode == "sftp-write" {
			file, err := client.Create(*path)
			must(err)
			_, err = io.Copy(file, io.LimitReader(os.Stdin, 4<<20))
			must(err)
			must(file.Close())
			return
		}
		file, err := client.Open(*path)
		must(err)
		defer file.Close()
		_, err = io.Copy(os.Stdout, io.LimitReader(file, 4<<20))
		must(err)
		return
	}
	if *mode != "request" {
		log.Fatal("invalid fixture mode")
	}
	roots := x509.NewCertPool()
	ca, err := os.ReadFile(filepath.Join(*dir, "ca.crt"))
	must(err)
	if !roots.AppendCertsFromPEM(ca) {
		log.Fatal("missing fixture CA")
	}
	data, err := io.ReadAll(io.LimitReader(os.Stdin, 4<<20))
	must(err)
	request, err := http.NewRequest(*method, *target, bytes.NewReader(data))
	must(err)
	request.Header.Set("Content-Type", *contentType)
	request.Header.Set("Accept", "application/json")
	if *token != "" {
		bearer, err := os.ReadFile(*token)
		must(err)
		request.Header.Set("Authorization", "Bearer "+string(bytes.TrimSpace(bearer)))
	}
	transport := &http.Transport{TLSClientConfig: &tls.Config{RootCAs: roots, MinVersion: tls.VersionTLS12}}
	client := &http.Client{Transport: transport, Timeout: 20 * time.Second, CheckRedirect: func(*http.Request, []*http.Request) error { return http.ErrUseLastResponse }}
	response, err := client.Do(request)
	must(err)
	defer response.Body.Close()
	if response.StatusCode < 200 || response.StatusCode >= 300 {
		fmt.Fprintf(os.Stderr, "native request status %d\n", response.StatusCode)
		os.Exit(1)
	}
	_, err = io.Copy(os.Stdout, io.LimitReader(response.Body, 4<<20))
	must(err)
}
