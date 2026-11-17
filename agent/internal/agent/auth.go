// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TeraLab contributors

package agent

import (
	"context"
	"crypto/subtle"
	"net"
	"net/http"
	"strings"
	"syscall"
)

type connKey struct{}

func connContext(ctx context.Context, connection net.Conn) context.Context {
	return context.WithValue(ctx, connKey{}, connection)
}

func (s *Server) authorized(request *http.Request) bool {
	uid, ok := peerUID(request)
	if !ok || !s.cfg.AllowedUIDs[uid] {
		return false
	}

	header := request.Header.Get("Authorization")
	if !strings.HasPrefix(header, "Bearer ") {
		return false
	}
	token := strings.TrimSpace(strings.TrimPrefix(header, "Bearer "))
	if token == "" {
		return false
	}

	return subtle.ConstantTimeCompare([]byte(token), []byte(s.cfg.Secret)) == 1
}

func peerUID(request *http.Request) (uint32, bool) {
	connection, ok := request.Context().Value(connKey{}).(net.Conn)
	if !ok {
		return 0, false
	}
	unixConnection, ok := connection.(*net.UnixConn)
	if !ok {
		return 0, false
	}
	raw, err := unixConnection.SyscallConn()
	if err != nil {
		return 0, false
	}

	var uid uint32
	var credential *syscall.Ucred
	var socketErr error
	if err := raw.Control(func(fd uintptr) {
		credential, socketErr = syscall.GetsockoptUcred(int(fd), syscall.SOL_SOCKET, syscall.SO_PEERCRED)
	}); err != nil || socketErr != nil || credential == nil {
		return 0, false
	}
	uid = credential.Uid

	return uid, true
}
