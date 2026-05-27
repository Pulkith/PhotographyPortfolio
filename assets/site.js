const HOST = "https://photography.pulkith.com";
const INDEX_URL = `${HOST}/admin/api.php?action=list`;

const gallery = document.querySelector("#gallery");
const heroImage = document.querySelector("#heroImage");
const lightbox = document.querySelector("#lightbox");
const lightboxImage = document.querySelector("#lightboxImage");
const lightboxLocation = document.querySelector("#lightboxLocation");
const lightboxDate = document.querySelector("#lightboxDate");
const closeLightbox = document.querySelector("#closeLightbox");
let renderedPhotos = [];
let resizeFrame = null;

function normalizePhotos(payload) {
  const photos = Array.isArray(payload) ? payload : payload?.photos;
  return (photos || [])
    .filter((photo) => photo && photo.url)
    .map((photo, index) => ({
      id: photo.id || `photo-${index}`,
      url: absolutePhotoUrl(photo.url),
      displayUrl: absolutePhotoUrl(photo.displayUrl || photo.url),
      previewUrl: absolutePhotoUrl(photo.previewUrl || photo.displayUrl || photo.url),
      thumbUrl: absolutePhotoUrl(photo.thumbUrl || photo.previewUrl || photo.displayUrl || photo.url),
      location: photo.location || "Location pending",
      date: photo.date || "",
      priority: clampNumber(photo.priority, 1, 10, 5),
      locationIndex: clampNumber(photo.locationIndex, 1, 100, index + 1),
      isLanding: photo.isLanding === true,
      aspectRatio: Number(photo.aspectRatio) || null,
      caption: photo.caption || ""
    }))
    .sort(sortPhotos);
}

function sortPhotos(a, b) {
  const dateCompare = dateValue(b.date) - dateValue(a.date);
  return dateCompare || a.locationIndex - b.locationIndex || b.priority - a.priority;
}

function dateValue(date) {
  const parsed = Date.parse(`${date || ""}T00:00:00`);
  return Number.isFinite(parsed) ? parsed : -Infinity;
}

function absolutePhotoUrl(url) {
  if (/^https?:\/\//i.test(url)) return url;
  return `${HOST}/photos/${String(url).replace(/^\/?photos\//, "")}`;
}

function clampNumber(value, min, max, fallback) {
  const number = Number(value);
  if (!Number.isFinite(number)) return fallback;
  return Math.max(min, Math.min(max, number));
}

function hashString(value) {
  let hash = 0;
  for (let i = 0; i < value.length; i += 1) {
    hash = (hash << 5) - hash + value.charCodeAt(i);
    hash |= 0;
  }
  return Math.abs(hash);
}

function priorityScale(priority) {
  return 0.72 + (priority / 10) * 0.7;
}

function photoAspect(photo) {
  return clampNumber(photo.aspectRatio, 0.45, 2.6, 1.45);
}

function displayWidth(photo, containerWidth, viewportWidth, viewportHeight) {
  const aspect = photoAspect(photo);
  const screenBase = clampNumber(viewportWidth * 0.14, 132, 260, 180);
  const preferredHeight = screenBase * priorityScale(photo.priority);
  const maxWidth = Math.min(containerWidth * 0.5, viewportWidth * 0.52);
  const maxHeightWidth = aspect * viewportHeight * 0.52;
  const jitter = 0.94 + (hashString(`${photo.id}-${photo.locationIndex}`) % 15) / 100;
  const preferredWidth = preferredHeight * aspect * jitter;

  return Math.round(clampNumber(preferredWidth, 118, Math.min(maxWidth, maxHeightWidth), 180));
}

function layoutRows(photos) {
  const containerWidth = gallery.clientWidth || gallery.getBoundingClientRect().width || window.innerWidth;
  const viewportWidth = window.innerWidth || containerWidth;
  const viewportHeight = window.innerHeight || 800;
  const gap = clampNumber(viewportWidth * 0.015, 12, 24, 16);
  const rows = [];
  let current = [];
  let currentWidth = 0;

  photos.forEach((photo, index) => {
    const width = displayWidth(photo, containerWidth, viewportWidth, viewportHeight);
    const tile = { photo, width };

    if (photo.priority >= 9) {
      if (current.length) {
        rows.push(current);
        current = [];
        currentWidth = 0;
      }
      rows.push([tile]);
      return;
    }

    const rowTarget = containerWidth * (0.78 + ((index % 4) * 0.055));
    const nextWidth = currentWidth + width + (current.length ? gap : 0);
    if (current.length && nextWidth > rowTarget) {
      rows.push(current);
      current = [tile];
      currentWidth = width;
      return;
    }

    current.push(tile);
    currentWidth = nextWidth;
  });

  if (current.length) rows.push(current);
  return rows;
}

function renderGallery(photos) {
  renderedPhotos = photos;
  gallery.innerHTML = "";
  if (!photos.length) {
    gallery.innerHTML = '<div class="empty-state">Upload photos from /admin to populate the portfolio.</div>';
    return;
  }

  const heroPhoto = photos.find((photo) => photo.isLanding) || [...photos].sort((a, b) => b.priority - a.priority || a.locationIndex - b.locationIndex)[0];
  heroImage.style.backgroundImage = `linear-gradient(180deg, rgba(0, 0, 0, 0.14), rgba(0, 0, 0, 0.72)), url("${heroPhoto.displayUrl}")`;

  const observer = new IntersectionObserver((entries) => {
    entries.forEach((entry) => {
      if (entry.isIntersecting) {
        entry.target.classList.add("is-visible");
        observer.unobserve(entry.target);
      }
    });
  }, { threshold: 0.12 });

  layoutRows(photos).forEach((row, rowIndex) => {
    const rowEl = document.createElement("div");
    rowEl.className = `gallery-row align-${["left", "right", "center"][rowIndex % 3]}`;
    if (row.length === 1 && row[0].photo.priority >= 9) rowEl.classList.add("feature-row");

    row.forEach(({ photo, width }) => {
      const isFeature = photo.priority >= 9;
      const button = document.createElement("button");
      button.type = "button";
      button.className = `photo-tile${isFeature ? " feature" : ""}`;
      button.style.width = `${width}px`;
      const sourceUrl = isFeature ? photo.displayUrl : photo.previewUrl;
      button.innerHTML = `
        <img src="${sourceUrl}" alt="${escapeHtml(photo.caption || `${photo.location} photograph`)}" loading="lazy" decoding="async">
        <span class="photo-meta">
          <span>${escapeHtml(photo.location)}</span>
          <span>${escapeHtml(photo.date)}</span>
        </span>
      `;
      button.addEventListener("click", () => openLightbox(photo));
      rowEl.appendChild(button);
      observer.observe(button);
    });
    gallery.appendChild(rowEl);
  });
}

function openLightbox(photo) {
  lightboxImage.src = photo.displayUrl;
  lightboxImage.alt = photo.caption || `${photo.location} photograph`;
  lightboxLocation.textContent = photo.location;
  lightboxDate.textContent = photo.date;
  lightbox.classList.add("open");
  document.body.style.overflow = "hidden";

  const original = new Image();
  original.onload = () => {
    if (lightbox.classList.contains("open")) {
      lightboxImage.src = photo.url;
    }
  };
  original.src = photo.url;
}

function closeViewer() {
  lightbox.classList.remove("open");
  lightboxImage.removeAttribute("src");
  document.body.style.overflow = "";
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

async function loadPhotos() {
  try {
    const response = await fetch(INDEX_URL, { cache: "no-store" });
    if (!response.ok) throw new Error(`HTTP ${response.status}`);
    const payload = await response.json();
    renderGallery(normalizePhotos(payload));
  } catch (error) {
    gallery.innerHTML = '<div class="empty-state">Could not load the hosted photo index.</div>';
  }
}

window.addEventListener("resize", () => {
  if (!renderedPhotos.length) return;
  window.cancelAnimationFrame(resizeFrame);
  resizeFrame = window.requestAnimationFrame(() => renderGallery(renderedPhotos));
});

closeLightbox.addEventListener("click", closeViewer);
lightbox.addEventListener("click", (event) => {
  if (event.target === lightbox) closeViewer();
});
document.addEventListener("keydown", (event) => {
  if (event.key === "Escape") closeViewer();
});

loadPhotos();
