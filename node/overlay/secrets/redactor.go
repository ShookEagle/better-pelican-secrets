package secrets

import (
	"bytes"
	"fmt"
	"io"
	"sync"
)

type literalNode struct {
	label    string
	terminal bool
	children map[byte]*literalNode
}
type literalRedactor struct{ state *redactionState }
type redactionState struct {
	mu          sync.Mutex
	root        literalNode
	destination io.Writer
	pending     []byte
	closed      bool
}

// NewRedactor matches literal bytes across writes, with leftmost-longest
// replacement. Its compressed trie shares immutable pattern bytes, avoiding
// one heap node per secret byte. The wrapper does not own the destination.
func NewRedactor(destination io.Writer, values []string) io.WriteCloser {
	state := &redactionState{destination: destination, root: literalNode{children: map[byte]*literalNode{}}}
	seen := map[string]bool{}
	for _, value := range values {
		if value != "" && !seen[value] {
			insertLiteral(&state.root, value)
			seen[value] = true
		}
	}
	return literalRedactor{state}
}
func insertLiteral(parent *literalNode, value string) {
	child, exists := parent.children[value[0]]
	if !exists {
		parent.children[value[0]] = &literalNode{label: value, terminal: true, children: map[byte]*literalNode{}}
		return
	}
	common := 0
	for common < min(len(child.label), len(value)) && child.label[common] == value[common] {
		common++
	}
	if common < len(child.label) {
		suffix := &literalNode{label: child.label[common:], terminal: child.terminal, children: child.children}
		child.label = child.label[:common]
		child.terminal = false
		child.children = map[byte]*literalNode{suffix.label[0]: suffix}
	}
	if common == len(value) {
		child.terminal = true
		return
	}
	insertLiteral(child, value[common:])
}
func (r literalRedactor) Write(data []byte) (int, error) {
	state := r.state
	if state == nil {
		return 0, io.ErrClosedPipe
	}
	state.mu.Lock()
	defer state.mu.Unlock()
	if state.closed || state.destination == nil {
		return 0, io.ErrClosedPipe
	}
	consumed := 0
	for len(data) > 0 {
		size := min(len(data), 16384)
		state.pending = append(state.pending, data[:size]...)
		output := state.process(false)
		if len(output) > 0 {
			n, err := state.destination.Write(output)
			if err != nil {
				return consumed, err
			}
			if n != len(output) {
				return consumed, io.ErrShortWrite
			}
		}
		consumed += size
		data = data[size:]
	}
	return consumed, nil
}
func (state *redactionState) process(final bool) []byte {
	var output bytes.Buffer
	cursor := 0
	for cursor < len(state.pending) {
		node := &state.root
		index := cursor
		best := 0
		waiting := false
		for index < len(state.pending) {
			child := node.children[state.pending[index]]
			if child == nil {
				break
			}
			common := 0
			for common < len(child.label) && index+common < len(state.pending) && child.label[common] == state.pending[index+common] {
				common++
			}
			if common < len(child.label) {
				waiting = !final && index+common == len(state.pending)
				break
			}
			index += common
			node = child
			if node.terminal {
				best = index - cursor
			}
			if index == len(state.pending) && len(node.children) > 0 && !final {
				waiting = true
			}
		}
		if waiting {
			break
		}
		if best > 0 {
			output.WriteString("[REDACTED]")
			cursor += best
		} else {
			output.WriteByte(state.pending[cursor])
			cursor++
		}
	}
	copy(state.pending, state.pending[cursor:])
	state.pending = state.pending[:len(state.pending)-cursor]
	return output.Bytes()
}
func (r literalRedactor) Close() error {
	state := r.state
	if state == nil {
		return nil
	}
	state.mu.Lock()
	defer state.mu.Unlock()
	if state.closed {
		return nil
	}
	state.closed = true
	output := state.process(true)
	state.pending = nil
	state.root = literalNode{}
	if len(output) == 0 {
		return nil
	}
	if state.destination == nil {
		return io.ErrClosedPipe
	}
	n, err := state.destination.Write(output)
	if err == nil && n != len(output) {
		return io.ErrShortWrite
	}
	return err
}
func (literalRedactor) String() string                    { return "[secret redactor]" }
func (literalRedactor) GoString() string                  { return "[secret redactor]" }
func (literalRedactor) Format(state fmt.State, verb rune) { io.WriteString(state, "[secret redactor]") }
