// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TeraLab contributors

package console

import (
	"context"
	"errors"
	"net"
	"net/http"
	"time"

	"github.com/SavEvens/TeraLab/agent/internal/engine"
	"github.com/SavEvens/TeraLab/agent/internal/token"
	"github.com/gorilla/websocket"
)

type Gateway struct {
	addr     string
	manager  *engine.Manager
	tokens   *token.Store
	listener net.Listener
	server   *http.Server
	upgrader websocket.Upgrader
}

func New(addr string, manager *engine.Manager, tokens *token.Store) *Gateway {
	return &Gateway{
		addr:    addr,
		manager: manager,
		tokens:  tokens,
		upgrader: websocket.Upgrader{
			ReadBufferSize:  4096,
			WriteBufferSize: 4096,
			CheckOrigin: func(request *http.Request) bool {
				return true
			},
		},
	}
}

func (g *Gateway) Start() error {
	host, _, err := net.SplitHostPort(g.addr)
	if err != nil {
		return err
	}
	if host != "127.0.0.1" && host != "localhost" {
		return errors.New("console gateway must bind to loopback")
	}

	listener, err := net.Listen("tcp", g.addr)
	if err != nil {
		return err
	}
	g.listener = listener

	mux := http.NewServeMux()
	mux.HandleFunc("/ws", g.handleWebSocket)
	g.server = &http.Server{
		Handler:           mux,
		ReadHeaderTimeout: 5 * time.Second,
		IdleTimeout:       60 * time.Second,
	}

	go func() {
		_ = g.server.Serve(listener)
	}()

	return nil
}

func (g *Gateway) Shutdown(ctx context.Context) error {
	if g.server == nil {
		return nil
	}

	return g.server.Shutdown(ctx)
}

func (g *Gateway) handleWebSocket(writer http.ResponseWriter, request *http.Request) {
	nodeID, ok := g.tokens.Resolve(request.URL.Query().Get("token"))
	if !ok {
		http.Error(writer, "invalid or expired console token", http.StatusUnauthorized)
		return
	}
	pty, ok := g.manager.PTY(nodeID)
	if !ok {
		http.Error(writer, "node is not running", http.StatusNotFound)
		return
	}

	connection, err := g.upgrader.Upgrade(writer, request, nil)
	if err != nil {
		return
	}
	defer connection.Close()

	done := make(chan struct{})
	go func() {
		defer close(done)
		defer func() { _ = connection.Close() }()
		buffer := make([]byte, 8192)
		for {
			count, readErr := pty.Read(buffer)
			if readErr != nil {
				return
			}
			if writeErr := connection.WriteMessage(websocket.BinaryMessage, buffer[:count]); writeErr != nil {
				return
			}
		}
	}()

	for {
		_, data, readErr := connection.ReadMessage()
		if readErr != nil {
			return
		}
		if _, writeErr := pty.Write(data); writeErr != nil {
			return
		}
	}
}
