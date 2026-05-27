const HOST = "https://photography.pulkith.com";
const API_URL = `${HOST}/admin/api.php`;
const INDEX_URL = `${API_URL}?action=list`;

const uploadForm = document.querySelector("#uploadForm");
const batchForm = document.querySelector("#batchForm");
const statusEl = document.querySelector("#status");
const batchStatus = document.querySelector("#batchStatus");
const batchProgressBar = document.querySelector("#batchProgressBar");
const listStatus = document.querySelector("#listStatus");
const photoList = document.querySelector("#photoList");
const saveButton = document.querySelector("#saveButton");
const refreshButton = document.querySelector("#refreshButton");
const renumberButton = document.querySelector("#renumberButton");
const batchUploadButton = document.querySelector("#batchUploadButton");
const optimizeButton = document.querySelector("#optimizeButton");

let photos = [];
let draggedId = null;
let batchUploading = false;

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
    caption: photo.caption || "",
    aspectRatio: Number(photo.aspectRatio) || null,
    uploadedAt: photo.uploadedAt || new Date().toISOString()
  })).sort(sortPhotos);
}

function sortPhotos(a, b) {
  return a.locationIndex - b.locationIndex || b.priority - a.priority;
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
  const response = await fetch(url, { cache: "no-store" });
  if (!response.ok) throw new Error(`HTTP ${response.status}`);
  return response.json();
}

async function uploadPhotoFormData(formData) {
  formData.append("action", "upload");
  const response = await fetch(API_URL, { method: "POST", body: formData });
  if (!response.ok) throw new Error(`HTTP ${response.status}`);
  return normalize(await response.json());
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
          ${fieldHtml("locationIndex", "Index", photo.locationIndex, "", "number", 1, 100)}
          ${fieldHtml("priority", "Priority", photo.priority, "", "number", 1, 10)}
          ${fieldHtml("caption", "Caption", photo.caption, "wide-field")}
        </div>
        <div class="admin-actions">
          <button class="drag-handle" type="button" title="Drag this row">Drag</button>
          <button data-action="up" type="button">Up</button>
          <button data-action="down" type="button">Down</button>
          <button data-action="delete" type="button">Delete</button>
        </div>
      </div>
    `;

    item.querySelectorAll("input").forEach((input) => {
      input.addEventListener("input", () => {
        const key = input.dataset.key;
        const nextValue = input.type === "number" ? Number(input.value) : input.value;
        photos[index][key] = nextValue;
      });
    });

    item.querySelector('[data-action="up"]').addEventListener("click", () => movePhoto(index, -1));
    item.querySelector('[data-action="down"]').addEventListener("click", () => movePhoto(index, 1));
    item.querySelector('[data-action="delete"]').addEventListener("click", () => deletePhoto(photo.id));
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

function fieldHtml(key, label, value, className = "", type = "text", min = "", max = "") {
  return `
    <label class="field ${className}">
      ${label}
      <input data-key="${key}" type="${type}" value="${escapeHtml(value ?? "")}" ${min !== "" ? `min="${min}"` : ""} ${max !== "" ? `max="${max}"` : ""}>
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
  const [photo] = photos.splice(index, 1);
  photos.splice(nextIndex, 0, photo);
  renumber();
  renderList();
}

function reorderByDrag(sourceId, targetId) {
  if (!sourceId || sourceId === targetId) return;
  const sourceIndex = photos.findIndex((photo) => photo.id === sourceId);
  const targetIndex = photos.findIndex((photo) => photo.id === targetId);
  if (sourceIndex < 0 || targetIndex < 0) return;
  const [photo] = photos.splice(sourceIndex, 1);
  photos.splice(targetIndex, 0, photo);
  draggedId = null;
  renumber();
  renderList();
}

function renumber() {
  const step = photos.length > 1 ? 99 / (photos.length - 1) : 0;
  photos.forEach((photo, index) => {
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
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ action: "delete", id, fileName: photo.fileName })
    });
    if (!response.ok) throw new Error(`HTTP ${response.status}`);
    setStatus("Deleted from server.", listStatus);
  } catch (error) {
    await loadPhotos();
    setStatus(`Delete failed against ${API_URL}.`, listStatus);
  }
}

function payload() {
  return {
    updatedAt: new Date().toISOString(),
    photos: photos.map((photo) => ({
      ...photo,
      priority: clamp(photo.priority, 1, 10, 5),
      locationIndex: clamp(photo.locationIndex, 1, 100, 50),
      url: absoluteUrl(photo.url || photo.fileName)
    })).sort(sortPhotos)
  };
}

async function saveChanges() {
  setStatus("Saving...", listStatus);
  try {
    const response = await fetch(API_URL, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ action: "save", data: payload() })
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
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ action: "optimize" })
    });
    if (!response.ok) throw new Error(`HTTP ${response.status}`);
    const payload = await response.json();
    photos = normalize(payload);
    renderList();
    setStatus(`Optimized ${payload.optimized || 0} existing image${payload.optimized === 1 ? "" : "s"}.`, listStatus);
  } catch (error) {
    setStatus(`Optimize failed against ${API_URL}.`, listStatus);
  } finally {
    optimizeButton.disabled = false;
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
renumberButton?.addEventListener("click", () => {
  renumber();
  renderList();
  setStatus("Location indexes renumbered. Save to persist.", listStatus);
});

loadPhotos();
