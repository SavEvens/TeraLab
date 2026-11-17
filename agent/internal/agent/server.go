// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TeraLab contributors

package agent

import (
	"context"
	"errors"
	"log/slog"
	"net"
	"net/http"
	"os"
	"path/filepath"
	"time"

	"github.com/SavEvens/TeraLab/agent/internal/config"
	"github.com/SavEvens/TeraLab/agent/internal/console"
	"github.com/SavEvens/TeraLab/agent/internal/engine"
	"github.com/SavEvens/TeraLab/agent/internal/token"
)

type Server struct {
	cfg        config.Config
	logger     *slog.Logger
	manager    *engine.Manager
	tokens     *token.Store
	gateway    *console.Gateway
	httpServer *http.Server
}

func New(cfg config.Config, logger *slog.Logger) *Server {
	manager := engine.NewManager(cfg, logger)
	tokens := token.New()

	return &Server{
		cfg:     cfg,
		logger:  logger,
		manager: manager,
		tokens:  tokens,
		gateway: console.New(cfg.ConsoleAddr, manager, tokens),
	}
}

func (s *Server) Run(ctx context.Context) error {
	socketDir := filepath.Dir(s.cfg.SocketPath)
	if err := os.MkdirAll(socketDir, 0o750); err != nil {
		return err
	}
	if s.cfg.SocketGID >= 0 {
		_ = os.Chown(socketDir, 0, s.cfg.SocketGID)
		_ = os.Chmod(socketDir, 0o750)
	}
	if err := os.MkdirAll(s.cfg.RunDir, 0o750); err != nil {
		return err
	}
	if err := os.MkdirAll(s.cfg.ImagesDir, 0o750); err != nil {
		return err
	}

	_ = os.Remove(s.cfg.SocketPath)
	listener, err := net.Listen("unix", s.cfg.SocketPath)
	if err != nil {
		return err
	}
	defer func() {
		_ = listener.Close()
		_ = os.Remove(s.cfg.SocketPath)
	}()
	if s.cfg.SocketGID >= 0 {
		_ = os.Chown(s.cfg.SocketPath, 0, s.cfg.SocketGID)
	}
	if err := os.Chmod(s.cfg.SocketPath, s.cfg.SocketMode); err != nil {
		return err
	}

	if err := s.gateway.Start(); err != nil {
		return err
	}

	s.httpServer = &http.Server{
		Handler:           s.routes(),
		ConnContext:       connContext,
		ReadHeaderTimeout: 5 * time.Second,
		IdleTimeout:       60 * time.Second,
	}

	serveErr := make(chan error, 1)
	go func() {
		serveErr <- s.httpServer.Serve(listener)
	}()
	s.logger.Info("agent listening", "socket", s.cfg.SocketPath, "console", s.cfg.ConsoleAddr)

	select {
	case <-ctx.Done():
		shutdownCtx, cancel := context.WithTimeout(context.Background(), 5*time.Second)
		defer cancel()
		_ = s.httpServer.Shutdown(shutdownCtx)
		_ = s.gateway.Shutdown(shutdownCtx)
		s.manager.Shutdown()
		return nil
	case err := <-serveErr:
		if errors.Is(err, http.ErrServerClosed) {
			return nil
		}
		return err
	}
}

func (s *Server) routes() http.Handler {
	mux := http.NewServeMux()
	mux.HandleFunc("GET /v1/health", s.requireAuth(s.handleHealth))
	mux.HandleFunc("GET /v1/system/status", s.requireAuth(s.handleSystemStatus))
	mux.HandleFunc("POST /v1/node.start", s.requireAuth(s.handleNodeStart))
	mux.HandleFunc("POST /v1/node.stop", s.requireAuth(s.handleNodeStop))
	mux.HandleFunc("POST /v1/node.wipe", s.requireAuth(s.handleNodeWipe))
	mux.HandleFunc("GET /v1/node.status", s.requireAuth(s.handleNodeStatus))
	mux.HandleFunc("POST /v1/console.open", s.requireAuth(s.handleConsoleOpen))

	return mux
}

func (s *Server) requireAuth(next http.HandlerFunc) http.HandlerFunc {
	return func(writer http.ResponseWriter, request *http.Request) {
		if !s.authorized(request) {
			writeError(writer, http.StatusUnauthorized, "unauthorized", "authentication required")
			return
		}
		next(writer, request)
	}
}
