// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TeraLab contributors

package agent

import (
	"context"
	"encoding/json"
	"errors"
	"io"
	"net/http"
	"path/filepath"
	"strconv"
	"syscall"
	"time"

	"github.com/SavEvens/TeraLab/agent/internal/config"
	"github.com/SavEvens/TeraLab/agent/internal/engine"
	"github.com/SavEvens/TeraLab/agent/internal/images"
	"github.com/SavEvens/TeraLab/agent/internal/system"
)

const maxRequestBody = 1 << 20

type apiError struct {
	Code    string `json:"code"`
	Message string `json:"message"`
}

type apiResponse struct {
	OK     bool      `json:"ok"`
	Result any       `json:"result,omitempty"`
	Error  *apiError `json:"error,omitempty"`
}

type nodeRequest struct {
	ID string `json:"id"`
}

type consoleRequest struct {
	NodeID string `json:"node_id"`
}

type labEnsureRequest struct {
	LabID    string `json:"lab_id"`
	Networks []struct {
		ID   string `json:"id"`
		Name string `json:"name"`
	} `json:"networks"`
}

type labCleanupRequest struct {
	LabID string `json:"lab_id"`
}

// cleanupStaleRequest carries the keep-set of labs that still exist. The body
// is optional: an absent or empty body leaves LabIDs nil, which keeps the
// node-level cleanup but leaves lab run directories alone.
type cleanupStaleRequest struct {
	LabIDs []string `json:"lab_ids"`
}

type imageInspectRequest struct {
	Path string `json:"path"`
}

func (s *Server) handleHealth(writer http.ResponseWriter, request *http.Request) {
	writeJSON(writer, http.StatusOK, apiResponse{
		OK: true,
		Result: map[string]any{
			"status":  "ok",
			"version": config.Version,
		},
	})
}

func (s *Server) handleSystemStatus(writer http.ResponseWriter, request *http.Request) {
	ctx, cancel := context.WithTimeout(request.Context(), 5*time.Second)
	defer cancel()
	writeJSON(writer, http.StatusOK, apiResponse{
		OK:     true,
		Result: system.Collect(ctx, s.cfg, s.manager),
	})
}

func (s *Server) handleNodeStart(writer http.ResponseWriter, request *http.Request) {
	var spec engine.Spec
	if err := readJSON(request, &spec); err != nil {
		writeError(writer, http.StatusBadRequest, "invalid_request", err.Error())
		return
	}

	info, err := s.manager.Start(spec)
	if err != nil {
		if errors.Is(err, engine.ErrEngineUnavailable) {
			writeError(writer, http.StatusServiceUnavailable, "engine_unavailable", err.Error())
			return
		}
		writeError(writer, http.StatusBadRequest, "start_failed", err.Error())
		return
	}

	writeJSON(writer, http.StatusOK, apiResponse{OK: true, Result: info})
}

func (s *Server) handleNodeStop(writer http.ResponseWriter, request *http.Request) {
	var payload nodeRequest
	if err := readJSON(request, &payload); err != nil {
		writeError(writer, http.StatusBadRequest, "invalid_request", err.Error())
		return
	}
	if err := s.manager.Stop(payload.ID); err != nil {
		writeError(writer, http.StatusNotFound, "not_found", err.Error())
		return
	}
	s.tokens.Revoke(payload.ID)

	writeJSON(writer, http.StatusOK, apiResponse{OK: true, Result: map[string]any{"state": "stopped"}})
}

func (s *Server) handleNodeWipe(writer http.ResponseWriter, request *http.Request) {
	var payload nodeRequest
	if err := readJSON(request, &payload); err != nil {
		writeError(writer, http.StatusBadRequest, "invalid_request", err.Error())
		return
	}
	if err := s.manager.Wipe(payload.ID); err != nil {
		writeError(writer, http.StatusBadRequest, "wipe_failed", err.Error())
		return
	}
	s.tokens.Revoke(payload.ID)

	writeJSON(writer, http.StatusOK, apiResponse{OK: true, Result: map[string]any{"state": "wiped"}})
}

func (s *Server) handleNodeStatus(writer http.ResponseWriter, request *http.Request) {
	id := request.URL.Query().Get("id")
	if id == "" {
		writeError(writer, http.StatusBadRequest, "invalid_request", "id is required")
		return
	}
	info, err := s.manager.Status(id)
	if err != nil {
		writeError(writer, http.StatusNotFound, "not_found", err.Error())
		return
	}

	writeJSON(writer, http.StatusOK, apiResponse{OK: true, Result: info})
}

func (s *Server) handleLabEnsure(writer http.ResponseWriter, request *http.Request) {
	var payload labEnsureRequest
	if err := readJSON(request, &payload); err != nil {
		writeError(writer, http.StatusBadRequest, "invalid_request", err.Error())
		return
	}
	if payload.LabID == "" {
		writeError(writer, http.StatusBadRequest, "invalid_request", "lab_id is required")
		return
	}
	ids := make([]string, 0, len(payload.Networks))
	for _, network := range payload.Networks {
		if network.ID == "" {
			writeError(writer, http.StatusBadRequest, "invalid_request", "network id is required")
			return
		}
		ids = append(ids, network.ID)
	}
	bridges, err := s.manager.EnsureLab(payload.LabID, ids)
	if err != nil {
		writeError(writer, http.StatusInternalServerError, "network_failed", err.Error())
		return
	}

	writeJSON(writer, http.StatusOK, apiResponse{OK: true, Result: map[string]any{
		"lab_id":  payload.LabID,
		"bridges": bridges,
	}})
}

func (s *Server) handleLabCleanup(writer http.ResponseWriter, request *http.Request) {
	var payload labCleanupRequest
	if err := readJSON(request, &payload); err != nil {
		writeError(writer, http.StatusBadRequest, "invalid_request", err.Error())
		return
	}
	if payload.LabID == "" {
		writeError(writer, http.StatusBadRequest, "invalid_request", "lab_id is required")
		return
	}
	removed, err := s.manager.CleanupLab(payload.LabID)
	if err != nil {
		writeError(writer, http.StatusInternalServerError, "network_failed", err.Error())
		return
	}

	writeJSON(writer, http.StatusOK, apiResponse{OK: true, Result: map[string]any{"removed": removed}})
}

func (s *Server) handleCleanupStale(writer http.ResponseWriter, request *http.Request) {
	serveCleanupStale(writer, request, s.manager.Reconcile)
}

// serveCleanupStale decodes the optional lab keep-set and reconciles. It takes
// the reconcile function so the decoding contract can be tested without a real
// run directory.
func serveCleanupStale(
	writer http.ResponseWriter,
	request *http.Request,
	reconcile func([]string) (engine.ReconcileResult, error),
) {
	var payload cleanupStaleRequest
	if err := readOptionalJSON(request, &payload); err != nil {
		writeError(writer, http.StatusBadRequest, "invalid_request", err.Error())
		return
	}
	result, err := reconcile(payload.LabIDs)
	if err != nil {
		writeError(writer, http.StatusInternalServerError, "reconcile_failed", err.Error())
		return
	}

	writeJSON(writer, http.StatusOK, apiResponse{OK: true, Result: result})
}

func (s *Server) handleEngines(writer http.ResponseWriter, request *http.Request) {
	ctx, cancel := context.WithTimeout(request.Context(), 5*time.Second)
	defer cancel()

	writeJSON(writer, http.StatusOK, apiResponse{OK: true, Result: system.Engines(ctx, s.cfg, s.manager)})
}

// handleDockerImages lists the container images already pulled on the agent
// host. A missing binary or an unreachable daemon is reported as 503 so the
// Inspector can explain why no docker image can be offered.
func (s *Server) handleDockerImages(writer http.ResponseWriter, request *http.Request) {
	images, err := s.manager.DockerImages(request.Context())
	if err != nil {
		// Manager.DockerImages reports every failure (missing binary,
		// unreachable daemon, failed listing) as ErrEngineUnavailable.
		writeError(writer, http.StatusServiceUnavailable, "engine_unavailable", err.Error())
		return
	}

	writeJSON(writer, http.StatusOK, apiResponse{OK: true, Result: map[string]any{"images": images}})
}

func (s *Server) handleImageInspect(writer http.ResponseWriter, request *http.Request) {
	var payload imageInspectRequest
	if err := readJSON(request, &payload); err != nil {
		writeError(writer, http.StatusBadRequest, "invalid_request", err.Error())
		return
	}
	if payload.Path == "" {
		writeError(writer, http.StatusBadRequest, "invalid_request", "path is required")
		return
	}
	if s.cfg.VMUID == 0 && !s.cfg.AllowRootVM {
		writeError(writer, http.StatusServiceUnavailable, "engine_unavailable",
			"teralab-vm user is missing; refusing to run qemu-img as root")
		return
	}
	resolved, err := images.ResolveUnderAny(payload.Path, s.cfg.ImageRoots())
	if err != nil {
		writeError(writer, http.StatusBadRequest, "invalid_path", err.Error())
		return
	}
	ctx, cancel := context.WithTimeout(request.Context(), 30*time.Second)
	defer cancel()
	info, err := images.InspectAs(ctx, s.cfg.QEMUImg, resolved, images.Command{
		Credential: vmCredential(s.cfg),
		Env: []string{
			"PATH=/usr/local/bin:/usr/bin:/bin",
			"HOME=" + filepath.Dir(resolved),
			"LC_ALL=C",
		},
	})
	if err != nil {
		writeError(writer, http.StatusUnprocessableEntity, "invalid_image", err.Error())
		return
	}

	writeJSON(writer, http.StatusOK, apiResponse{OK: true, Result: info})
}

func (s *Server) handleConsoleOpen(writer http.ResponseWriter, request *http.Request) {
	var payload consoleRequest
	if err := readJSON(request, &payload); err != nil {
		writeError(writer, http.StatusBadRequest, "invalid_request", err.Error())
		return
	}
	info, err := s.manager.Status(payload.NodeID)
	if err != nil {
		writeError(writer, http.StatusNotFound, "not_found", err.Error())
		return
	}
	if info.State != "running" {
		writeError(writer, http.StatusConflict, "not_running", "node is not running")
		return
	}
	expiry := 5 * time.Minute
	token := s.tokens.Issue(payload.NodeID, expiry)
	if token == "" {
		writeError(writer, http.StatusInternalServerError, "token_failed", "cannot issue console token")
		return
	}

	result := map[string]any{
		"kind":       info.Console,
		"token":      token,
		"expires_in": int(expiry.Seconds()),
	}
	if port, ok := s.manager.TCPConsole(payload.NodeID); ok && info.Console != "vnc" {
		result["kind"] = "telnet"
		result["port"] = port
		result["ws_url"] = "ws://" + s.cfg.ConsoleAddr + "/tcp?token=" + token
		writeJSON(writer, http.StatusOK, apiResponse{OK: true, Result: result})
		return
	}
	if info.Console == "vnc" {
		port, password, ok := s.manager.VNC(payload.NodeID)
		if !ok {
			writeError(writer, http.StatusConflict, "not_running", "node has no vnc console")
			return
		}
		result["port"] = port
		result["password"] = password
		result["ws_url"] = "ws://" + s.cfg.ConsoleAddr + "/vnc?token=" + token
	} else {
		result["ws_url"] = "ws://" + s.cfg.ConsoleAddr + "/ws?token=" + token
	}

	writeJSON(writer, http.StatusOK, apiResponse{OK: true, Result: result})
}

// vmCredential is the unprivileged engine identity for qemu-img. A zero UID
// means the VM user could not be resolved; the agent then refuses at start
// time rather than silently running image parsers as root.
func vmCredential(cfg config.Config) *syscall.Credential {
	if cfg.VMUID == 0 {
		return nil
	}

	return &syscall.Credential{Uid: cfg.VMUID, Gid: cfg.VMGID, Groups: cfg.VMGroups}
}

func readJSON(request *http.Request, destination any) error {
	reader := io.LimitReader(request.Body, maxRequestBody+1)
	decoder := json.NewDecoder(reader)
	decoder.DisallowUnknownFields()
	if err := decoder.Decode(destination); err != nil {
		return err
	}

	return nil
}

// readOptionalJSON is readJSON for endpoints whose body may be omitted. An
// empty body leaves destination untouched instead of failing with io.EOF.
func readOptionalJSON(request *http.Request, destination any) error {
	reader := io.LimitReader(request.Body, maxRequestBody+1)
	decoder := json.NewDecoder(reader)
	decoder.DisallowUnknownFields()
	if err := decoder.Decode(destination); err != nil && !errors.Is(err, io.EOF) {
		return err
	}

	return nil
}

func writeJSON(writer http.ResponseWriter, status int, payload apiResponse) {
	body, err := json.Marshal(payload)
	if err != nil {
		// The payload types are plain structs; this only triggers on a bug.
		body = []byte(`{"ok":false,"error":{"code":"encode_failed","message":"cannot encode response"}}`)
		status = http.StatusInternalServerError
	}
	body = append(body, '\n')
	writer.Header().Set("Content-Type", "application/json; charset=utf-8")
	writer.Header().Set("Cache-Control", "no-store")
	// Set the length explicitly: writing the header before the body makes the
	// Go server use chunked encoding for large payloads, which the Unix-socket
	// PHP client does not decode.
	writer.Header().Set("Content-Length", strconv.Itoa(len(body)))
	writer.WriteHeader(status)
	_, _ = writer.Write(body)
}

func writeError(writer http.ResponseWriter, status int, code, message string) {
	writeJSON(writer, status, apiResponse{
		OK:    false,
		Error: &apiError{Code: code, Message: message},
	})
}
