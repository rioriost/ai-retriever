(function () {
  "use strict";

  var config = window.ritrieverBackfill || null;
  if (!config) {
    return;
  }

  var root = document.getElementById("ritriever-backfill-progress");
  if (!root) {
    return;
  }

  var statusText = root.querySelector("[data-ritriever-status-text]");
  var detailText = root.querySelector("[data-ritriever-detail-text]");
  var bar = root.querySelector("[data-ritriever-progress-bar]");
  var percentText = root.querySelector("[data-ritriever-percent]");
  var controls = root.querySelectorAll("[data-ritriever-control]");
  var activeWorkers = 0;
  var stopped = false;
  var currentStatus = "idle";
  var consecutiveErrors = 0;

  function setMessage(message) {
    if (detailText && message) {
      detailText.textContent = message;
    }
  }

  function setControlStates(status) {
    Array.prototype.forEach.call(controls, function (button) {
      var action = button.getAttribute("data-ritriever-control");
      if (action === "pause") {
        button.disabled = !(status === "queued" || status === "running");
      } else if (action === "resume") {
        button.disabled = status !== "paused";
      } else if (action === "cancel") {
        button.disabled = !(
          status === "queued" ||
          status === "running" ||
          status === "paused"
        );
      }
    });
  }

  function updateProgress(state) {
    if (!state) {
      return;
    }

    var total = Math.max(0, parseInt(state.total, 10) || 0);
    var processed = Math.max(0, parseInt(state.processed, 10) || 0);
    var errors = Math.max(0, parseInt(state.errors, 10) || 0);
    var percent =
      total > 0 ? Math.floor((Math.min(processed, total) / total) * 100) : 0;

    currentStatus = state.status || "idle";
    if (bar) {
      bar.style.width = percent + "%";
    }
    if (percentText) {
      percentText.textContent = percent + "%";
    }
    if (statusText) {
      statusText.textContent = config.i18n.progress
        .replace("%1$d", processed)
        .replace("%2$d", total)
        .replace("%3$d", errors);
    }
    if (state.message) {
      setMessage(state.message);
    }
    setControlStates(currentStatus);
  }

  function request(action) {
    var formData = new window.FormData();
    formData.append("action", action);
    formData.append("nonce", config.nonce);

    return window
      .fetch(config.ajaxUrl, {
        method: "POST",
        credentials: "same-origin",
        body: formData,
      })
      .then(function (response) {
        if (!response.ok) {
          throw new Error("HTTP " + response.status);
        }
        return response.json();
      })
      .then(function (payload) {
        if (!payload || !payload.success) {
          var message =
            payload && payload.data && payload.data.message
              ? payload.data.message
              : config.i18n.failed;
          throw new Error(message);
        }
        return payload.data;
      });
  }

  function isRunnable(status) {
    return status === "queued" || status === "running";
  }

  function finish(state) {
    stopped = true;
    updateProgress(state);
    if (state && state.status === "complete") {
      setMessage(config.i18n.complete);
      return;
    }
    if (state && state.status === "failed") {
      setMessage(config.i18n.failed);
      return;
    }
    if (state && state.status === "paused") {
      setMessage(state.message || config.i18n.idle);
      return;
    }
    if (state && state.status === "cancelled") {
      setMessage(state.message || config.i18n.failed);
      return;
    }
    setMessage(config.i18n.idle);
  }

  function workerTick() {
    if (stopped || !isRunnable(currentStatus)) {
      return;
    }

    activeWorkers += 1;
    setMessage(config.i18n.running);

    request("ritriever_backfill_run")
      .then(function (state) {
        activeWorkers -= 1;
        consecutiveErrors = 0;
        updateProgress(state);
        if (isRunnable(state.status)) {
          window.setTimeout(workerTick, config.delayMs || 500);
        } else if (activeWorkers <= 0) {
          finish(state);
        }
      })
      .catch(function (error) {
        activeWorkers -= 1;
        consecutiveErrors += 1;
        if (consecutiveErrors >= (config.maxConsecutiveErrors || 5)) {
          stopped = true;
          setMessage(config.i18n.failed + " " + error.message);
          setControlStates(currentStatus);
          return;
        }
        setMessage(config.i18n.retrying.replace("%s", error.message));
        window.setTimeout(workerTick, config.errorDelayMs || 5000);
      });
  }

  function startWorkers() {
    if (stopped || !isRunnable(currentStatus)) {
      return;
    }

    var desired = Math.max(
      1,
      Math.min(3, parseInt(config.concurrency, 10) || 1),
    );
    while (activeWorkers < desired) {
      workerTick();
    }
  }

  function control(action) {
    if (action === "cancel" && !window.confirm(config.i18n.confirmCancel)) {
      return;
    }

    if (action === "resume") {
      stopped = false;
      consecutiveErrors = 0;
    }

    request("ritriever_backfill_" + action)
      .then(function (state) {
        updateProgress(state);
        if (action === "pause" || action === "cancel") {
          stopped = true;
          setMessage(state.message);
          return;
        }
        if (action === "resume" && isRunnable(state.status)) {
          stopped = false;
          startWorkers();
        }
      })
      .catch(function (error) {
        setMessage(config.i18n.retrying.replace("%s", error.message));
      });
  }

  Array.prototype.forEach.call(controls, function (button) {
    button.addEventListener("click", function () {
      control(button.getAttribute("data-ritriever-control"));
    });
  });

  request("ritriever_backfill_status")
    .then(function (state) {
      updateProgress(state);
      if (config.autoStart && isRunnable(state.status)) {
        stopped = false;
        startWorkers();
      } else {
        setControlStates(state.status);
      }
    })
    .catch(function (error) {
      consecutiveErrors += 1;
      setMessage(config.i18n.retrying.replace("%s", error.message));
      window.setTimeout(startWorkers, config.errorDelayMs || 5000);
    });
})();
