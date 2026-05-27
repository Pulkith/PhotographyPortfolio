const HOST = "https://photography.pulkith.com";
const API_URL = `${HOST}/admin/api.php`;
const INDEX_URL = `${API_URL}?action=list`;

const adminLock = document.querySelector("#adminLock");
const loginForm = document.querySelector("#loginForm");
const passwordInput = document.querySelector("#passwordInput");
const loginStatus = document.querySelector("#loginStatus");
const uploadForm = document.querySelector("#uploadForm");
const batchForm = document.querySelector("#batchForm");
const statusEl = document.querySelector("#status");
const batchStatus = document.querySelector("#batchStatus");
const batchProgressBar = document.querySelector("#batchProgressBar");
const listStatus = document.querySelector("#listStatus");
const photoList = document.querySelector("#photoList");
const toast = document.querySelector("#toast");
const saveButton = document.querySelector("#saveButton");
const refreshButton = document.querySelector("#refreshButton");
const renumberButton = document.querySelector("#renumberButton");
const batchUploadButton = document.querySelector("#batchUploadButton");
const optimizeButton = document.querySelector("#optimizeButton");
const diagnosticsButton = document.querySelector("#diagnosticsButton");
const deriveMetadataButton = document.querySelector("#deriveMetadataButton");

let photos = [];
let draggedId = null;
let batchUploading = false;
let authToken = localStorage.getItem("photographyAdminToken") || "";
let toastTimer = null;

document.body.classList.add("admin-locked");

function setStatus(message, target = statusEl) {
  if (!target) return;
  target.textContent = message;
}

function normalize(payload) {
  const list = Array.isArray(payload) ? payload : payload?.photos;
  return (list || []).map((photo, index) => ({
    id: photo.id || `photo-${Date.now()}-${index}`,
    fileName: photo.fileName || filenameFromUrl(photo.url) || "",
    url: absoluteUrl(photo.url || photo.fileName || ""),
    displayFileName: photo.displayFileName || filenameFromUrl(photo.displayUrl) || null,
    displayUrl: absoluteUrl(photo.displayUrl || photo.url || photo.fileName || ""),
    previewFileName: photo.previewFileName || filenameFromUrl(photo.previewUrl) || null,
    previewUrl: absoluteUrl(photo.previewUrl || photo.displayUrl || photo.url || photo.fileName || ""),
    thumbFileName: photo.thumbFileName || filenameFromUrl(photo.thumbUrl) || null,
    thumbUrl: absoluteUrl(photo.thumbUrl || photo.previewUrl || photo.displayUrl || photo.url || photo.fileName || ""),
    location: photo.location || "",
    date: photo.date || "",
    priority: clamp(photo.priority, 1, 10, 5),
    locationIndex: clamp(photo.locationIndex, 1, 100, index + 1),
    isLanding: photo.isLanding === true,
    caption: photo.caption || "",
    latitude: photo.latitude !== null && photo.latitude !== undefined && photo.latitude !== "" && Number.isFinite(Number(photo.latitude)) ? Number(photo.latitude) : null,
    longitude: photo.longitude !== null && photo.longitude !== undefined && photo.longitude !== "" && Number.isFinite(Number(photo.longitude)) ? Number(photo.longitude) : null,
    aspectRatio: Number(photo.aspectRatio) || null,
    uploadedAt: photo.uploadedAt || new Date().toISOString()
  })).sort(sortPhotos);
}

function sortPhotos(a, b) {
  const dateCompare = dateValue(b.date) - dateValue(a.date);
  return dateCompare || a.locationIndex - b.locationIndex || b.priority - a.priority;
}

function dateValue(date) {
  const parsed = Date.parse(`${date || ""}T00:00:00`);
  return Number.isFinite(parsed) ? parsed : -Infinity;
}

function sameDate(a, b) {
  return (a?.date || "") === (b?.date || "");
}

function showOrderError() {
  showToast("Images can only be reordered within the same date.");
}

function showToast(message) {
  if (!toast) {
    setStatus(message, listStatus);
    return;
  }
  toast.textContent = message;
  toast.classList.add("show");
  clearTimeout(toastTimer);
  toastTimer = setTimeout(() => {
    toast.classList.remove("show");
  }, 2800);
}

function absoluteUrl(value) {
  if (!value) return "";
  if (/^https?:\/\//i.test(value)) return value;
  return `${HOST}/photos/${String(value).replace(/^\/?photos\//, "")}`;
}

function filenameFromUrl(value) {
  if (!value) return "";
  try {
    return decodeURIComponent(new URL(value, HOST).pathname.split("/").pop() || "");
  } catch (error) {
    return String(value).split("/").pop() || "";
  }
}

function clamp(value, min, max, fallback) {
  const number = Number(value);
  if (!Number.isFinite(number)) return fallback;
  return Math.max(min, Math.min(max, number));
}

async function fetchJson(url) {
  const headers = authToken ? { Authorization: `Bearer ${authToken}` } : {};
  const response = await fetch(withToken(url), { cache: "no-store", credentials: "include", headers });
  if (!response.ok) throw new Error(`HTTP ${response.status}`);
  return response.json();
}

function withToken(url) {
  if (!authToken || !url.startsWith(API_URL)) return url;
  const separator = url.includes("?") ? "&" : "?";
  return `${url}${separator}token=${encodeURIComponent(authToken)}`;
}

async function apiPost(body, options = {}) {
  let nextBody = body;
  if (authToken && body instanceof FormData) {
    body.set("token", authToken);
  } else if (authToken && typeof body === "string" && options.headers?.["Content-Type"] === "application/json") {
    try {
      const parsed = JSON.parse(body);
      nextBody = JSON.stringify({ ...parsed, token: authToken });
    } catch (error) {
      nextBody = body;
    }
  }
  const headers = {
    ...(options.headers || {}),
    ...(authToken ? { Authorization: `Bearer ${authToken}` } : {})
  };
  const response = await fetch(API_URL, {
    method: "POST",
    credentials: "include",
    ...options,
    headers,
    body: nextBody
  });
  if (!response.ok) throw new Error(`HTTP ${response.status}`);
  return response.json();
}

function unlockAdmin() {
  document.body.classList.remove("admin-locked");
  if (adminLock) adminLock.hidden = true;
}

function lockAdmin(message = "Enter the admin password.") {
  document.body.classList.add("admin-locked");
  if (adminLock) adminLock.hidden = false;
  setStatus(message, loginStatus);
}

async function checkAuth() {
  try {
    const payload = await fetchJson(`${API_URL}?action=authStatus`);
    if (payload.authenticated) {
      unlockAdmin();
      await loadPhotos();
    } else {
      lockAdmin();
    }
  } catch (error) {
    lockAdmin("Could not check admin authentication.");
  }
}

async function login(password) {
  setStatus("Checking password...", loginStatus);
  try {
    authToken = "";
    localStorage.removeItem("photographyAdminToken");
    const loginPayload = await apiPost(JSON.stringify({ action: "login", password }), {
      headers: { "Content-Type": "application/json" }
    });
    if (loginPayload.token) {
      authToken = loginPayload.token;
      localStorage.setItem("photographyAdminToken", authToken);
    }
    const status = await fetchJson(`${API_URL}?action=authStatus`);
    if (!status.authenticated) {
      lockAdmin("Password accepted, but the server did not accept the auth token.");
      return;
    }
    passwordInput.value = "";
    unlockAdmin();
    await loadPhotos();
  } catch (error) {
    lockAdmin("Incorrect password.");
  }
}

async function uploadPhotoFormData(formData) {
  formData.append("action", "upload");
  return normalize(await apiPost(formData));
}

async function loadPhotos() {
  setStatus("Loading images...", listStatus);
  try {
    photos = normalize(await fetchJson(INDEX_URL));
    renderList();
    setStatus(`Loaded ${photos.length} image${photos.length === 1 ? "" : "s"} from hosted API.`, listStatus);
  } catch (error) {
    photos = [];
    renderList();
    setStatus(`Could not load ${INDEX_URL}.`, listStatus);
  }
}

function renderList() {
  if (!photoList) return;
  photoList.innerHTML = "";
  if (!photos.length) {
    photoList.innerHTML = '<div class="empty-state">No images yet.</div>';
    return;
  }

  photos.forEach((photo, index) => {
    const item = document.createElement("article");
    item.className = "admin-item";
    item.draggable = true;
    item.dataset.id = photo.id;
    item.innerHTML = `
      <img class="admin-thumb" src="${photo.thumbUrl}" alt="" loading="lazy" decoding="async">
      <div>
        <div class="admin-fields">
          ${fieldHtml("location", "Location", photo.location, "wide-field")}
          ${fieldHtml("date", "Date", photo.date, "", "date")}
          ${fieldHtml("locationIndex", "Index", photo.locationIndex, "", "number", 1, 100, true)}
          ${fieldHtml("priority", "Priority", photo.priority, "", "number", 1, 10)}
          ${fieldHtml("caption", "Caption", photo.caption, "wide-field")}
        </div>
        <div class="admin-actions">
          <button data-action="landing" type="button">${photo.isLanding ? "Landing Image" : "Set Landing"}</button>
          <button data-action="replace" type="button">Replace Image</button>
          <input class="admin-replace-input" data-action="replaceFile" type="file" accept="image/*">
          <button class="drag-handle" type="button" title="Drag this row">Drag</button>
          <button data-action="up" type="button">Up</button>
          <button data-action="down" type="button">Down</button>
          <button data-action="delete" type="button">Delete</button>
        </div>
      </div>
    `;

    item.querySelectorAll("input[data-key]").forEach((input) => {
      input.addEventListener("input", () => {
        const key = input.dataset.key;
        const nextValue = input.type === "number" ? Number(input.value) : input.value;
        photos[index][key] = nextValue;
      });
    });

    item.querySelector('[data-action="up"]').addEventListener("click", () => movePhoto(index, -1));
    item.querySelector('[data-action="down"]').addEventListener("click", () => movePhoto(index, 1));
    item.querySelector('[data-action="delete"]').addEventListener("click", () => deletePhoto(photo.id));
    item.querySelector('[data-action="landing"]').addEventListener("click", () => setLandingPhoto(photo.id));
    item.querySelector('[data-action="replace"]').addEventListener("click", () => {
      item.querySelector('[data-action="replaceFile"]').click();
    });
    item.querySelector('[data-action="replaceFile"]').addEventListener("change", (event) => {
      replacePhoto(photo.id, event.target.files?.[0], event.target);
    });
    item.addEventListener("dragstart", () => {
      draggedId = photo.id;
      item.classList.add("dragging");
    });
    item.addEventListener("dragend", () => item.classList.remove("dragging"));
    item.addEventListener("dragover", (event) => event.preventDefault());
    item.addEventListener("drop", (event) => {
      event.preventDefault();
      reorderByDrag(draggedId, photo.id);
    });
    photoList.appendChild(item);
  });
}

function setLandingPhoto(id) {
  photos = photos.map((photo) => ({
    ...photo,
    isLanding: photo.id === id
  }));
  renderList();
  saveChanges();
}

function fieldHtml(key, label, value, className = "", type = "text", min = "", max = "", disabled = false) {
  return `
    <label class="field ${className}">
      ${label}
      <input data-key="${key}" type="${type}" value="${escapeHtml(value ?? "")}" ${min !== "" ? `min="${min}"` : ""} ${max !== "" ? `max="${max}"` : ""} ${disabled ? "disabled" : ""}>
    </label>
  `;
}

function escapeHtml(value) {
  return String(value).replace(/[&<>"']/g, (char) => ({
    "&": "&amp;",
    "<": "&lt;",
    ">": "&gt;",
    '"': "&quot;",
    "'": "&#39;"
  })[char]);
}

function movePhoto(index, delta) {
  const nextIndex = index + delta;
  if (nextIndex < 0 || nextIndex >= photos.length) return;
  if (!sameDate(photos[index], photos[nextIndex])) {
    showOrderError();
    return;
  }
  const [photo] = photos.splice(index, 1);
  photos.splice(nextIndex, 0, photo);
  renumberDateGroup(photo.date);
  renderList();
}

function reorderByDrag(sourceId, targetId) {
  if (!sourceId || sourceId === targetId) return;
  const sourceIndex = photos.findIndex((photo) => photo.id === sourceId);
  const targetIndex = photos.findIndex((photo) => photo.id === targetId);
  if (sourceIndex < 0 || targetIndex < 0) return;
  if (!sameDate(photos[sourceIndex], photos[targetIndex])) {
    draggedId = null;
    showOrderError();
    return;
  }
  const [photo] = photos.splice(sourceIndex, 1);
  photos.splice(targetIndex, 0, photo);
  draggedId = null;
  renumberDateGroup(photo.date);
  renderList();
}

function renumberDateGroup(date) {
  const group = photos.filter((photo) => (photo.date || "") === (date || ""));
  const step = group.length > 1 ? 99 / (group.length - 1) : 0;
  group.forEach((photo, index) => {
    photo.locationIndex = Math.round(1 + step * index);
  });
}

async function deletePhoto(id) {
  const photo = photos.find((item) => item.id === id);
  if (!photo) return;
  photos = photos.filter((item) => item.id !== id);
  renderList();
  try {
    const response = await fetch(API_URL, {
      method: "POST",
      credentials: "include",
      headers: {
        "Content-Type": "application/json",
        ...(authToken ? { Authorization: `Bearer ${authToken}` } : {})
      },
      body: JSON.stringify({ action: "delete", id, fileName: photo.fileName, token: authToken })
    });
    if (!response.ok) throw new Error(`HTTP ${response.status}`);
    setStatus("Deleted from server.", listStatus);
  } catch (error) {
    await loadPhotos();
    setStatus(`Delete failed against ${API_URL}.`, listStatus);
  }
}

async function replacePhoto(id, file, input) {
  if (!file) return;
  const photo = photos.find((item) => item.id === id);
  if (!photo) return;

  setStatus(`Replacing image for ${photo.location || photo.fileName || "photo"}...`, listStatus);
  const formData = new FormData();
  formData.append("action", "replace");
  formData.append("id", id);
  formData.append("photo", file);

  try {
    photos = normalize(await apiPost(formData));
    renderList();
    setStatus("Image replaced. Metadata, order, priority, and location were preserved.", listStatus);
  } catch (error) {
    setStatus(`Replace failed against ${API_URL}.`, listStatus);
  } finally {
    if (input) input.value = "";
  }
}

function payload() {
  return {
    updatedAt: new Date().toISOString(),
    photos: photos.map((photo) => ({
      id: photo.id,
      fileName: photo.fileName,
      url: absoluteUrl(photo.url || photo.fileName),
      location: photo.location,
      date: photo.date,
      priority: clamp(photo.priority, 1, 10, 5),
      locationIndex: clamp(photo.locationIndex, 1, 100, 50),
      isLanding: photo.isLanding === true,
      caption: photo.caption,
      latitude: photo.latitude,
      longitude: photo.longitude,
      aspectRatio: photo.aspectRatio,
      uploadedAt: photo.uploadedAt
    })).sort(sortPhotos)
  };
}

async function saveChanges() {
  setStatus("Saving...", listStatus);
  try {
    const response = await fetch(API_URL, {
      method: "POST",
      credentials: "include",
      headers: {
        "Content-Type": "application/json",
        ...(authToken ? { Authorization: `Bearer ${authToken}` } : {})
      },
      body: JSON.stringify({ action: "save", data: payload(), token: authToken })
    });
    if (!response.ok) throw new Error(`HTTP ${response.status}`);
    photos = normalize(await response.json());
    renderList();
    setStatus(`Saved to ${INDEX_URL}.`, listStatus);
  } catch (error) {
    setStatus(`Save failed against ${API_URL}.`, listStatus);
  }
}

async function optimizeExisting() {
  setStatus("Generating faster display images for existing uploads...", listStatus);
  optimizeButton.disabled = true;
  try {
    const response = await fetch(API_URL, {
      method: "POST",
      credentials: "include",
      headers: {
        "Content-Type": "application/json",
        ...(authToken ? { Authorization: `Bearer ${authToken}` } : {})
      },
      body: JSON.stringify({ action: "optimize", token: authToken })
    });
    if (!response.ok) throw new Error(`HTTP ${response.status}`);
    const payload = await response.json();
    photos = normalize(payload);
    renderList();
    const failed = payload.optimizeFailed || 0;
    const failures = Array.isArray(payload.optimizeFailures) ? payload.optimizeFailures : [];
    setStatus(
      failed
        ? `Optimized ${payload.optimized || 0}; ${failed} failed. ${failures.join(" | ")}`
        : `Optimized ${payload.optimized || 0} existing image${payload.optimized === 1 ? "" : "s"}.`,
      listStatus
    );
  } catch (error) {
    setStatus(`Optimize failed against ${API_URL}.`, listStatus);
  } finally {
    optimizeButton.disabled = false;
  }
}

async function deriveMetadata() {
  setStatus("Deriving taken dates and GPS locations from original files...", listStatus);
  deriveMetadataButton.disabled = true;
  try {
    const response = await fetch(API_URL, {
      method: "POST",
      credentials: "include",
      headers: {
        "Content-Type": "application/json",
        ...(authToken ? { Authorization: `Bearer ${authToken}` } : {})
      },
      body: JSON.stringify({ action: "deriveMetadata", token: authToken })
    });
    if (!response.ok) throw new Error(`HTTP ${response.status}`);
    const payload = await response.json();
    photos = normalize(payload);
    renderList();
    setStatus(
      `Metadata updated on ${payload.metadataDerived || 0} image${payload.metadataDerived === 1 ? "" : "s"}: ${payload.datesDerived || 0} date${payload.datesDerived === 1 ? "" : "s"}, ${payload.locationsDerived || 0} location${payload.locationsDerived === 1 ? "" : "s"}.`,
      listStatus
    );
  } catch (error) {
    setStatus(`Metadata derivation failed against ${API_URL}.`, listStatus);
  } finally {
    deriveMetadataButton.disabled = false;
  }
}

async function checkOptimization() {
  setStatus("Checking optimization support...", listStatus);
  if (diagnosticsButton) diagnosticsButton.disabled = true;
  try {
    const response = await fetch(API_URL, {
      method: "POST",
      credentials: "include",
      headers: {
        "Content-Type": "application/json",
        ...(authToken ? { Authorization: `Bearer ${authToken}` } : {})
      },
      body: JSON.stringify({ action: "diagnostics", token: authToken })
    });
    if (!response.ok) throw new Error(`HTTP ${response.status}`);
    const payload = await response.json();
    setStatus(
      `GD: ${payload.gdAvailable ? "yes" : "no"}, JPEG: ${payload.jpegAvailable ? "yes" : "no"}, display writable: ${payload.displayDirWritable ? "yes" : "no"}, thumbs writable: ${payload.thumbDirWritable ? "yes" : "no"}, display files: ${payload.displayFileCount}, thumb files: ${payload.thumbFileCount}.`,
      listStatus
    );
  } catch (error) {
    setStatus(`Optimization check failed against ${API_URL}.`, listStatus);
  } finally {
    if (diagnosticsButton) diagnosticsButton.disabled = false;
  }
}

uploadForm?.addEventListener("submit", async (event) => {
  event.preventDefault();
  setStatus("Uploading original file...");
  const formData = new FormData(uploadForm);

  try {
    photos = await uploadPhotoFormData(formData);
    renderList();
    uploadForm.reset();
    document.querySelector("#priorityInput").value = 5;
    document.querySelector("#locationIndexInput").value = 50;
    setStatus("Uploaded original file and updated index.");
  } catch (error) {
    setStatus(`Upload failed against ${API_URL}.`);
  }
});

async function uploadBatch() {
  if (batchUploading) return;

  const fileInput = document.querySelector("#batchFileInput");
  const button = document.querySelector("#batchUploadButton");
  const progressBar = document.querySelector("#batchProgressBar");
  const files = Array.from(fileInput.files || []);
  if (!files.length) {
    setStatus("Choose one or more image files first.", batchStatus);
    return;
  }

  batchUploading = true;
  if (button) button.disabled = true;
  if (progressBar) progressBar.style.width = "0%";
  setStatus(`Uploading 0 of ${files.length} files...`, batchStatus);

  let uploaded = 0;
  const failed = [];

  for (const file of files) {
    setStatus(`Uploading ${uploaded + failed.length + 1} of ${files.length}: ${file.name}`, batchStatus);
    const formData = new FormData();
    formData.append("photo", file);
    formData.append("location", "");
    formData.append("date", "");
    formData.append("locationIndex", "50");
    formData.append("priority", "5");
    formData.append("caption", "");

    try {
      photos = await uploadPhotoFormData(formData);
      uploaded += 1;
    } catch (error) {
      failed.push(file.name);
    }

    const completed = uploaded + failed.length;
    if (progressBar) progressBar.style.width = `${Math.round((completed / files.length) * 100)}%`;
    setStatus(`Uploaded ${uploaded} of ${files.length} files${failed.length ? `, ${failed.length} failed` : ""}.`, batchStatus);
  }

  renderList();
  batchUploading = false;
  if (button) button.disabled = false;

  if (failed.length) {
    setStatus(`Batch finished: ${uploaded} uploaded, ${failed.length} failed. Failed: ${failed.join(", ")}`, batchStatus);
  } else {
    batchForm.reset();
    setStatus(`Batch finished: uploaded ${uploaded} file${uploaded === 1 ? "" : "s"}.`, batchStatus);
  }
}

batchForm?.addEventListener("submit", (event) => {
  event.preventDefault();
});
document.addEventListener("click", (event) => {
  if (event.target?.id === "batchUploadButton") {
    event.preventDefault();
    uploadBatch();
  }
});

saveButton?.addEventListener("click", saveChanges);
refreshButton?.addEventListener("click", loadPhotos);
optimizeButton?.addEventListener("click", optimizeExisting);
diagnosticsButton?.addEventListener("click", checkOptimization);
deriveMetadataButton?.addEventListener("click", deriveMetadata);
loginForm?.addEventListener("submit", (event) => {
  event.preventDefault();
  login(passwordInput.value);
});
renumberButton?.addEventListener("click", () => {
  setStatus("Index is disabled. Reorder images within the same date using drag, Up, or Down.", listStatus);
});

checkAuth();
