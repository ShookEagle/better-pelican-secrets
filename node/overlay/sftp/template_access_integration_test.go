//go:build linux && bps_integration

package sftp

import (
	"archive/tar"
	"bytes"
	"compress/gzip"
	"context"
	"crypto/ed25519"
	"crypto/rand"
	"encoding/json"
	"errors"
	"io"
	"net"
	"net/http"
	"net/http/httptest"
	"net/url"
	"os"
	"path/filepath"
	"strings"
	"testing"
	"time"

	"github.com/creasty/defaults"
	"github.com/docker/docker/api/types/container"
	"github.com/docker/docker/api/types/mount"
	"github.com/docker/docker/client"
	"github.com/docker/docker/pkg/stdcopy"
	"github.com/gbrlsnchs/jwt/v3"
	"github.com/google/uuid"
	pkgsftp "github.com/pkg/sftp"
	"golang.org/x/crypto/ssh"

	"github.com/pelican/wings/config"
	"github.com/pelican/wings/internal/database"
	"github.com/pelican/wings/remote"
	"github.com/pelican/wings/router"
	"github.com/pelican/wings/router/tokens"
	"github.com/pelican/wings/secrets"
	"github.com/pelican/wings/server"
	"github.com/pelican/wings/server/backup"
)

type templateAccessRemote struct {
	remote.Client
	serverID string
}

func (c templateAccessRemote) ValidateSftpCredentials(_ context.Context, request remote.SftpAuthRequest) (remote.SftpAuthResponse, error) {
	if request.Pass != "fixture-password" {
		return remote.SftpAuthResponse{}, errors.New("invalid fixture credential")
	}
	return remote.SftpAuthResponse{Server: c.serverID, User: "55555555-5555-4555-8555-555555555555", Permissions: []string{"file.read", "file.read-content", "file.update"}}, nil
}
func (c templateAccessRemote) ReportSecretApplication(context.Context, string, secrets.AppliedReport) error {
	return nil
}

func TestTemplateAccessNativePathsLive(t *testing.T) {
	dataBase, runtimeFile, image := os.Getenv("BPS_LIVE_DATA_BASE"), os.Getenv("BPS_LIVE_RUNTIME_FILE"), os.Getenv("BPS_LIVE_IMAGE")
	if dataBase == "" || runtimeFile == "" || image == "" {
		t.Fatal("explicit live template-access fixture required")
	}
	cfg := &config.Configuration{AuthenticationToken: "fixture-node-token", AuthenticationTokenId: "fixtureid"}
	if err := defaults.Set(cfg); err != nil {
		t.Fatal(err)
	}
	cfg.System.Data = dataBase
	cfg.System.RootDirectory = t.TempDir()
	cfg.System.BackupDirectory = t.TempDir()
	cfg.System.User.Uid = 1000
	cfg.System.User.Gid = 1000
	cfg.System.User.Passwd.Enable = false
	cfg.System.MachineID.Enable = false
	cfg.System.CheckPermissionsOnBoot = false
	cfg.System.Quotas.Enabled = false
	config.Set(cfg)
	if err := database.Initialize(); err != nil {
		t.Fatal(err)
	}
	corpusBytes, err := os.ReadFile(filepath.Join(os.Getenv("BPS_PROTOCOL_FIXTURES"), "protocol.json"))
	if err != nil {
		t.Fatal(err)
	}
	var corpus struct {
		Plan   json.RawMessage
		Bundle map[string]any
		Report json.RawMessage
	}
	if err := json.Unmarshal(corpusBytes, &corpus); err != nil {
		t.Fatal(err)
	}
	plan, err := secrets.DecodePlan(corpus.Plan)
	if err != nil {
		t.Fatal(err)
	}
	sourceRoot := filepath.Join(dataBase, plan.ServerUUID)
	metadata := templateAccessRemote{serverID: plan.ServerUUID}
	manager := server.NewEmptyManager(metadata)
	settings, _ := json.Marshal(map[string]any{"uuid": plan.ServerUUID, "secrets": plan, "environment": map[string]string{},
		"allocations": map[string]any{"default": map[string]any{"ip": "127.0.0.1", "port": 0}}})
	srv, err := manager.InitServer(remote.ServerConfigurationResponse{Settings: settings, SecretPlan: &plan, ProcessConfiguration: &remote.ProcessConfiguration{}})
	if err != nil {
		t.Fatal(err)
	}
	defer srv.CleanupForDestroy()
	manager.Add(srv)
	cli, err := client.NewClientWithOpts(client.FromEnv, client.WithAPIVersionNegotiation())
	if err != nil {
		t.Fatal(err)
	}
	defer cli.Close()
	gameName := os.Getenv("BPS_LIVE_GAME")
	if gameName == "" {
		t.Fatal("explicit owned game fixture name required")
	}
	game, err := cli.ContainerCreate(context.Background(), &container.Config{Image: image, User: "1000:1000", Cmd: []string{"sleep", "90"}, Labels: map[string]string{"better-pelican-secrets.dev": "true"}},
		&container.HostConfig{NetworkMode: "none", LogConfig: container.LogConfig{Type: "none"}, ReadonlyRootfs: true,
			Mounts: []mount.Mount{{Type: mount.TypeBind, Source: sourceRoot, Target: "/game"}, {Type: mount.TypeBind, Source: runtimeFile, Target: "/game/csgo/config.json", ReadOnly: true}}}, nil, nil, gameName)
	if err != nil {
		t.Fatal(err)
	}
	defer cli.ContainerRemove(context.Background(), game.ID, container.RemoveOptions{Force: true, RemoveVolumes: true})
	if err := cli.ContainerStart(context.Background(), game.ID, container.StartOptions{}); err != nil {
		t.Fatal(err)
	}
	if templateGameRead(t, cli, game.ID) != "{\"key\":\"fixture-secret\"}" {
		t.Fatal("game did not receive runtime copy")
	}

	httpServer := httptest.NewServer(router.Configure(manager, metadata))
	defer httpServer.Close()
	request := func(method, path, body string) []byte {
		t.Helper()
		req, err := http.NewRequest(method, httpServer.URL+path, strings.NewReader(body))
		if err != nil {
			t.Fatal(err)
		}
		req.Header.Set("Authorization", "Bearer fixture-node-token")
		req.Header.Set("Content-Type", "application/json")
		response, err := http.DefaultClient.Do(req)
		if err != nil {
			t.Fatal(err)
		}
		defer response.Body.Close()
		contents, err := io.ReadAll(io.LimitReader(response.Body, 4194305))
		if err != nil || response.StatusCode >= 300 {
			t.Fatalf("native file route failed: %s %d", path, response.StatusCode)
		}
		return contents
	}
	assertTemplate := func(contents []byte) {
		t.Helper()
		if string(contents) != "{\"key\":{{secret:KEY}}}" || bytes.Contains(contents, []byte("fixture-secret")) {
			t.Fatal("ordinary access did not return placeholder source")
		}
	}
	assertTemplate(request("GET", "/api/servers/"+plan.ServerUUID+"/files/contents?file=csgo%2Fconfig.json", ""))
	payload := tokens.FilePayload{Payload: jwt.Payload{IssuedAt: jwt.NumericDate(time.Now().Add(2 * time.Second)), ExpirationTime: jwt.NumericDate(time.Now().Add(time.Minute)), JWTID: uuid.NewString()},
		FilePath: "csgo/config.json", ServerUuid: plan.ServerUUID, UserUuid: "55555555-5555-4555-8555-555555555555", UniqueId: uuid.NewString(), Scoped: tokens.Scoped{Scope: string(tokens.FileDownload)}}
	token, err := jwt.Sign(payload, config.GetJwtAlgorithm())
	if err != nil {
		t.Fatal(err)
	}
	assertTemplate(request("GET", "/download/file?token="+url.QueryEscape(string(token)), ""))

	// Use the native SFTP credential callback, SSH handshake, session dispatcher,
	// permissions, and file handler with no secrets permissions.
	sftpServer := New(manager)
	_, private, err := ed25519.GenerateKey(rand.Reader)
	if err != nil {
		t.Fatal(err)
	}
	signer, err := ssh.NewSignerFromKey(private)
	if err != nil {
		t.Fatal(err)
	}
	sshConfig := &ssh.ServerConfig{PasswordCallback: func(conn ssh.ConnMetadata, password []byte) (*ssh.Permissions, error) {
		return sftpServer.makeCredentialsRequest(conn, remote.SftpAuthPassword, string(password))
	}}
	sshConfig.AddHostKey(signer)
	listener, err := net.Listen("tcp", "127.0.0.1:0")
	if err != nil {
		t.Fatal(err)
	}
	defer listener.Close()
	go func() {
		connection, err := listener.Accept()
		if err == nil {
			defer connection.Close()
			sftpServer.AcceptInbound(connection, sshConfig)
		}
	}()
	sshClient, err := ssh.Dial("tcp", listener.Addr().String(), &ssh.ClientConfig{User: "developer.11111111", Auth: []ssh.AuthMethod{ssh.Password("fixture-password")}, HostKeyCallback: ssh.FixedHostKey(signer.PublicKey()), Timeout: 5 * time.Second})
	if err != nil {
		t.Fatal(err)
	}
	defer sshClient.Close()
	sftpClient, err := pkgsftp.NewClient(sshClient)
	if err != nil {
		t.Fatal(err)
	}
	defer sftpClient.Close()
	sourceFile, err := sftpClient.Open("csgo/config.json")
	if err != nil {
		t.Fatal(err)
	}
	content, err := io.ReadAll(sourceFile)
	sourceFile.Close()
	if err != nil {
		t.Fatal(err)
	}
	assertTemplate(content)

	request("POST", "/api/servers/"+plan.ServerUUID+"/files/compress", `{"root":"/csgo","files":["config.json"],"name":"source-check","extension":"tar.gz"}`)
	assertTemplate(templateArchiveMember(t, filepath.Join(sourceRoot, "csgo/source-check.tar.gz"), "config.json"))
	local := backup.NewLocal(metadata, uuid.NewString(), plan.ServerUUID, "")
	if _, err := local.Generate(context.Background(), srv.Filesystem(), ""); err != nil {
		t.Fatal(err)
	}
	assertTemplate(templateArchiveMember(t, local.Path(), "csgo/config.json"))

	// A normal developer can introduce an invalid reference through SFTP. The
	// existing running snapshot stays immutable and the next preparation rejects it.
	writer, err := sftpClient.OpenFile("csgo/config.json", os.O_WRONLY|os.O_TRUNC)
	if err != nil {
		t.Fatal(err)
	}
	if _, err := writer.Write([]byte("{\"key\":{{secret:OTHER}}}")); err != nil {
		t.Fatal(err)
	}
	writer.Close()
	if templateGameRead(t, cli, game.ID) != "{\"key\":\"fixture-secret\"}" {
		t.Fatal("source edit changed running runtime snapshot")
	}
	corpus.Bundle["attempt_id"] = uuid.NewString()
	wire, _ := json.Marshal(corpus.Bundle)
	bundle, err := secrets.DecodeBundle(wire)
	if err != nil {
		t.Fatal(err)
	}
	root, err := os.OpenRoot(sourceRoot)
	if err != nil {
		t.Fatal(err)
	}
	defer root.Close()
	runtimeBase := filepath.Dir(filepath.Dir(filepath.Dir(runtimeFile)))
	prepared, err := (&secrets.Preparer{RuntimeBase: runtimeBase}).Prepare(context.Background(), plan, bundle, root, secrets.DataMapping{HostRoot: sourceRoot, ContainerRoot: "/game"}, secrets.GameIdentity{ContainerUID: 1000, ContainerGID: 1000, HostUID: 1000, HostGID: 1000})
	if err == nil {
		prepared.Close()
		t.Fatal("invalid SFTP reference accepted on next start")
	}
	if err.Error() != "manifest_mismatch" {
		t.Fatalf("unexpected fixed rejection: %v", err)
	}
}

func templateGameRead(t *testing.T, cli *client.Client, id string) string {
	t.Helper()
	execution, err := cli.ContainerExecCreate(context.Background(), id, container.ExecOptions{Cmd: []string{"cat", "/game/csgo/config.json"}, AttachStdout: true, AttachStderr: true})
	if err != nil {
		t.Fatal(err)
	}
	stream, err := cli.ContainerExecAttach(context.Background(), execution.ID, container.ExecAttachOptions{})
	if err != nil {
		t.Fatal(err)
	}
	defer stream.Close()
	var stdout, stderr bytes.Buffer
	if _, err := stdcopy.StdCopy(&stdout, &stderr, stream.Reader); err != nil {
		t.Fatal(err)
	}
	status, err := cli.ContainerExecInspect(context.Background(), execution.ID)
	if err != nil || status.ExitCode != 0 {
		t.Fatal("game fixture exec failed")
	}
	return stdout.String()
}
func templateArchiveMember(t *testing.T, name, member string) []byte {
	t.Helper()
	file, err := os.Open(name)
	if err != nil {
		t.Fatal(err)
	}
	defer file.Close()
	gz, err := gzip.NewReader(file)
	if err != nil {
		t.Fatal(err)
	}
	defer gz.Close()
	archive := tar.NewReader(gz)
	for {
		header, err := archive.Next()
		if err == io.EOF {
			break
		}
		if err != nil {
			t.Fatal(err)
		}
		if strings.TrimPrefix(header.Name, "./") == member {
			bytes, err := io.ReadAll(io.LimitReader(archive, 4194305))
			if err != nil {
				t.Fatal(err)
			}
			return bytes
		}
	}
	t.Fatal("template member absent from native archive")
	return nil
}
