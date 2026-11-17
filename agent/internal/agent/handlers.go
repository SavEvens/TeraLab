// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TeraLab contributors

package agent

import (
	"context"
	"encoding/json"
	"errors"
	"io"
	"net/http"
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
	result, err := s.manager.Reconcile()
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
	resolved, err := images.ResolveUnder(payload.Path, s.cfg.ImagesDir)
	if err != nil {
		writeError(writer, http.StatusBadRequest, "invalid_path", err.Error())
		return
	}
	ctx, cancel := context.WithTimeout(request.Context(), 30*time.Second)
	defer cancel()
	info, err := images.Inspect(ctx, s.cfg.QEMUImg, resolved)
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

func readJSON(request *http.Request, destination any) error {
	reader := io.LimitReader(request.Body, maxRequestBody+1)
	decoder := json.NewDecoder(reader)
	decoder.DisallowUnknownFields()
	if err := decoder.Decode(destination); err != nil {
		return err
	}

	return nil
}

func writeJSON(writer http.ResponseWriter, status int, payload apiResponse) {
	writer.Header().Set("Content-Type", "application/json; charset=utf-8")
	writer.Header().Set("Cache-Control", "no-store")
	writer.WriteHeader(status)
	if err := json.NewEncoder(writer).Encode(payload); err != nil {
		return
	}
}

func writeError(writer http.ResponseWriter, status int, code, message string) {
	writeJSON(writer, status, apiResponse{
		OK:    false,
		Error: &apiError{Code: code, Message: message},
	})
}
