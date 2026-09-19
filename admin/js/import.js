/**
 * IBG Client Outreach – CSV import wizard.
 *
 * Step 1: drag-and-drop enhancement over the native file input, and the
 * "Subscribed requires confirmation" toggle.
 * Step 3: runs the import in AJAX batches with a progress bar.
 */
(function () {
  "use strict";

  var cfg = window.ibgOutreachImport || {};

  function sprintf(text) {
    var args = Array.prototype.slice.call(arguments, 1);
    return text.replace(/%(\d+)\$s/g, function (match, n) {
      return args[n - 1] !== undefined ? args[n - 1] : match;
    });
  }

  /* ---- Step 1: upload form ---------------------------------------- */

  var dropzone = document.getElementById("ibg-dropzone");
  var input = document.getElementById("ibg-csv");
  var fileName = document.getElementById("ibg-dropzone-file");

  function showFile() {
    if (!input || !fileName) {
      return;
    }
    if (input.files && input.files.length) {
      fileName.textContent =
        input.files[0].name +
        " (" +
        Math.round(input.files[0].size / 1024) +
        " KB)";
      fileName.hidden = false;
    } else {
      fileName.hidden = true;
    }
  }

  if (dropzone && input) {
    ["dragenter", "dragover"].forEach(function (evt) {
      dropzone.addEventListener(evt, function (e) {
        e.preventDefault();
        dropzone.classList.add("is-dragover");
      });
    });
    ["dragleave", "drop"].forEach(function (evt) {
      dropzone.addEventListener(evt, function (e) {
        e.preventDefault();
        dropzone.classList.remove("is-dragover");
      });
    });
    dropzone.addEventListener("drop", function (e) {
      if (
        e.dataTransfer &&
        e.dataTransfer.files &&
        e.dataTransfer.files.length
      ) {
        try {
          input.files = e.dataTransfer.files;
        } catch (err) {
          // Older browsers: fall back to the file picker.
        }
        showFile();
      }
    });
    dropzone.addEventListener("keydown", function (e) {
      if ("Enter" === e.key || " " === e.key) {
        e.preventDefault();
        input.click();
      }
    });
    input.addEventListener("change", showFile);
  }

  var subscribedRadio = document.getElementById("ibg-import-subscribed");
  var confirmBox = document.getElementById("ibg-import-confirm");
  if (subscribedRadio && confirmBox) {
    var radios = document.querySelectorAll('input[name="marketing_status"]');
    Array.prototype.forEach.call(radios, function (radio) {
      radio.addEventListener("change", function () {
        confirmBox.hidden = !subscribedRadio.checked;
      });
    });
  }

  /* ---- Step 3: batched import ------------------------------------- */

  var run = document.getElementById("ibg-import-run");
  if (!run || !cfg.token) {
    return;
  }

  var startBtn = document.getElementById("ibg-import-start");
  var controls = document.getElementById("ibg-import-controls");
  var progress = document.getElementById("ibg-import-progress");
  var bar = document.getElementById("ibg-import-bar");
  var status = document.getElementById("ibg-import-status");
  var result = document.getElementById("ibg-import-result");
  var title = document.getElementById("ibg-import-result-title");
  var errors = document.getElementById("ibg-import-errors");
  var cancel = document.getElementById("ibg-import-cancel");
  var total = parseInt(run.getAttribute("data-total"), 10) || 0;
  var running = false;

  function setText(id, value) {
    var el = document.getElementById(id);
    if (el) {
      el.textContent = value;
    }
  }

  function render(p) {
    var pct = total
      ? Math.min(100, Math.round((100 * p.processed) / total))
      : 100;
    bar.style.width = pct + "%";
    status.textContent = sprintf(cfg.i18n.importing, p.processed, total);

    [
      "created",
      "updated",
      "skipped_invalid",
      "skipped_existing",
      "preserved",
      "failed",
      "listed",
    ].forEach(function (key) {
      setText("ibg-r-" + key, p[key]);
    });

    errors.innerHTML = "";
    (p.errors || []).forEach(function (err) {
      var li = document.createElement("li");
      li.textContent = "#" + err.row + " " + err.email + " — " + err.message;
      errors.appendChild(li);
    });
  }

  function finish(p) {
    running = false;
    render(p);
    progress.hidden = true;
    result.hidden = false;
    title.textContent = cfg.i18n.done;
    if (cancel) {
      cancel.hidden = true;
    }
  }

  function fail(message) {
    running = false;
    status.textContent = message || cfg.i18n.error;
    status.classList.add("ibg-status-error");
    controls.hidden = false;
  }

  function batch() {
    var body = new window.FormData();
    body.append("action", cfg.action);
    body.append("nonce", cfg.nonce);
    body.append("token", cfg.token);

    window
      .fetch(cfg.ajaxUrl, {
        method: "POST",
        credentials: "same-origin",
        body: body,
      })
      .then(function (response) {
        return response.json();
      })
      .then(function (json) {
        if (!json || !json.success) {
          fail(json && json.data && json.data.message ? json.data.message : "");
          return;
        }
        var p = json.data.progress;
        total = json.data.total || total;
        if (p.done) {
          finish(p);
        } else {
          render(p);
          batch();
        }
      })
      .catch(function () {
        fail("");
      });
  }

  if (startBtn) {
    startBtn.addEventListener("click", function () {
      if (running) {
        return;
      }
      running = true;
      controls.hidden = true;
      progress.hidden = false;
      result.hidden = false;
      status.classList.remove("ibg-status-error");
      batch();
    });
  }

  window.addEventListener("beforeunload", function (e) {
    if (running) {
      e.preventDefault();
      e.returnValue = cfg.i18n.leave;
      return cfg.i18n.leave;
    }
  });
})();
