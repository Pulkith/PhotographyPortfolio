const HOST = "https://photography.pulkith.com";
const INDEX_URL = `${HOST}/photos/index.json`;
const API_INDEX_URL = `${HOST}/admin/api.php?action=list`;

const gallery = document.querySelector("#gallery");
const heroImage = document.querySelector("#heroImage");
const lightbox = document.querySelector("#lightbox");
const lightboxImage = document.querySelector("#lightboxImage");
const lightboxLocation = document.querySelector("#lightboxLocation");
const lightboxDate = document.querySelector("#lightboxDate");
const closeLightbox = document.querySelector("#closeLightbox");
const prevLightbox = document.querySelector("#prevLightbox");
const nextLightbox = document.querySelector("#nextLightbox");
let renderedPhotos = [];
let resizeFrame = null;
let activePhotoIndex = -1;
let layoutWidth = 0;
const preloadedImages = new Set();

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
      responsiveUrls: responsiveUrls(photo),
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

function responsiveUrls(photo) {
  const displayUrl = absolutePhotoUrl(photo.displayUrl || photo.url);
  const previewUrl = absolutePhotoUrl(photo.previewUrl || photo.displayUrl || photo.url);
  const thumbUrl = absolutePhotoUrl(photo.thumbUrl || photo.previewUrl || photo.displayUrl || photo.url);
  return [
    { url: thumbUrl, width: 220 },
    { url: previewUrl, width: 540 },
    { url: displayUrl, width: 1080 }
  ];
}

function srcset(photo) {
  return photo.responsiveUrls.map((item) => `${item.url} ${item.width}w`).join(", ");
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
  return 0.86 + ((priority - 1) / 9) * 0.78;
}

function photoAspect(photo) {
  return clampNumber(photo.aspectRatio, 0.45, 2.6, 1.45);
}

function tileBounds(photo, containerWidth, viewportWidth, viewportHeight) {
  const aspect = photoAspect(photo);
  const isNarrow = viewportWidth <= 640;
  const baseHeight = isNarrow
    ? clampNumber(viewportWidth * 0.24, 170, 330, 220)
    : clampNumber(viewportWidth * 0.15, 150, 280, 200);
  const preferredHeight = baseHeight * priorityScale(photo.priority);
  const minHeight = clampNumber(viewportWidth * (isNarrow ? 0.18 : 0.13), 138, isNarrow ? 260 : 220, 170) * (0.94 + photo.priority * 0.02);
  const maxHeight = Math.min(
    viewportHeight * ((isNarrow ? 0.58 : 0.52) + photo.priority * (isNarrow ? 0.04 : 0.032)),
    preferredHeight * (1.35 + photo.priority * 0.16),
    clampNumber(viewportWidth * ((isNarrow ? 0.42 : 0.3) + photo.priority * (isNarrow ? 0.058 : 0.042)), 340, isNarrow ? 760 : 980, 520)
  );
  const maxWidth = Math.min(
    containerWidth * ((isNarrow ? 0.64 : 0.42) + photo.priority * (isNarrow ? 0.052 : 0.06)),
    viewportWidth * ((isNarrow ? 0.72 : 0.58) + photo.priority * (isNarrow ? 0.03 : 0.042)),
    maxHeight * aspect
  );
  const minWidth = minHeight * aspect;

  return { aspect, preferredHeight, minWidth, maxWidth };
}

function displayWidth(photo, containerWidth, viewportWidth, viewportHeight) {
  const { aspect, preferredHeight, minWidth, maxWidth } = tileBounds(photo, containerWidth, viewportWidth, viewportHeight);
  const jitter = 0.94 + (hashString(`${photo.id}-${photo.locationIndex}`) % 15) / 100;
  const preferredWidth = preferredHeight * aspect * jitter;

  return Math.round(clampNumber(preferredWidth, minWidth, Math.max(minWidth, maxWidth), 200));
}

function rowTargetWidth(rowIndex, containerWidth) {
  return containerWidth * (0.97 + ((rowIndex % 2) * 0.015));
}

function scaleRow(row, rowIndex, containerWidth, viewportWidth, viewportHeight, gap) {
  const targetWidth = rowTargetWidth(rowIndex, containerWidth);
  let widths = row.map((tile) => tile.width);
  let total = widths.reduce((sum, width) => sum + width, 0) + gap * Math.max(0, row.length - 1);
  let remaining = targetWidth - total;
  let iterations = 0;

  const heightTarget = Math.max(...row.map((tile, index) => widths[index] / photoAspect(tile.photo)));
  row
    .map((tile, index) => ({ tile, index }))
    .sort((a, b) => b.tile.photo.priority - a.tile.photo.priority || a.index - b.index)
    .forEach(({ tile, index }) => {
      if (remaining <= 1) return;
      const { maxWidth } = tileBounds(tile.photo, containerWidth, viewportWidth, viewportHeight);
      const priorityHeightTarget = heightTarget * (0.86 + tile.photo.priority * 0.014);
      const desiredWidth = priorityHeightTarget * photoAspect(tile.photo);
      const addition = Math.min(Math.max(0, desiredWidth - widths[index]), Math.max(0, maxWidth - widths[index]), remaining);
      widths[index] += addition;
      remaining -= addition;
    });

  while (remaining > 1 && iterations < 4) {
    const growable = row
      .map((tile, index) => {
        const { maxWidth } = tileBounds(tile.photo, containerWidth, viewportWidth, viewportHeight);
        return { index, room: Math.max(0, maxWidth - widths[index]), priority: tile.photo.priority };
      })
      .filter((item) => item.room > 0)
      .sort((a, b) => b.priority - a.priority || b.room - a.room);

    if (!growable.length) break;

    growable.forEach((item) => {
      if (remaining <= 0) return;
      const addition = Math.min(item.room, remaining);
      widths[item.index] += addition;
      remaining -= addition;
    });

    total = widths.reduce((sum, width) => sum + width, 0) + gap * Math.max(0, row.length - 1);
    remaining = targetWidth - total;
    iterations += 1;
  }

  return row.map((tile, index) => ({ ...tile, width: Math.round(widths[index]) }));
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

    const rowTarget = rowTargetWidth(rows.length, containerWidth);
    const nextWidth = currentWidth + width + (current.length ? gap : 0);
    if (current.length && nextWidth > rowTarget) {
      rows.push(scaleRow(current, rows.length, containerWidth, viewportWidth, viewportHeight, gap));
      current = [tile];
      currentWidth = width;
      return;
    }

    current.push(tile);
    currentWidth = nextWidth;
  });

  if (current.length) rows.push(scaleRow(current, rows.length, containerWidth, viewportWidth, viewportHeight, gap));
  return rows;
}

function renderGallery(photos) {
  renderedPhotos = photos;
  layoutWidth = window.innerWidth || gallery.clientWidth || 0;
  gallery.innerHTML = "";
  if (!photos.length) {
    gallery.innerHTML = '<div class="empty-state">Upload photos from /admin to populate the portfolio.</div>';
    return;
  }

  const heroPhoto = photos.find((photo) => photo.isLanding) || [...photos].sort((a, b) => b.priority - a.priority || a.locationIndex - b.locationIndex)[0];
  setFastHero(heroPhoto);

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
    rowEl.className = "gallery-row";

    row.forEach(({ photo, width }) => {
      const isFeature = photo.priority >= 9;
      const button = document.createElement("button");
      button.type = "button";
      button.className = `photo-tile${isFeature ? " feature" : ""}`;
      button.style.width = `${width}px`;
      const sourceUrl = photo.thumbUrl;
      button.innerHTML = `
        <img src="${sourceUrl}" srcset="${srcset(photo)}" sizes="${Math.min(Math.ceil(width), 540)}px" alt="${escapeHtml(photo.caption || `${photo.location} photograph`)}" loading="${rowIndex < 2 ? "eager" : "lazy"}" fetchpriority="${rowIndex === 0 ? "high" : "auto"}" decoding="async">
        <span class="photo-meta">
          <span>${escapeHtml(photo.location)}</span>
          <span>${escapeHtml(photo.date)}</span>
        </span>
      `;
      button.addEventListener("click", () => openLightbox(renderedPhotos.findIndex((item) => item.id === photo.id)));
      rowEl.appendChild(button);
      window.requestAnimationFrame(() => {
        button.classList.add("is-visible");
        observer.unobserve(button);
      });
    });
    gallery.appendChild(rowEl);
  });
}

function setFastHero(photo) {
  heroImage.removeAttribute("srcset");
  heroImage.removeAttribute("sizes");
  heroImage.src = photo.thumbUrl;

  const upgrade = () => {
    const image = new Image();
    image.onload = () => {
      heroImage.src = photo.previewUrl;
    };
    image.src = photo.previewUrl;
  };

  if ("requestIdleCallback" in window) {
    window.requestIdleCallback(upgrade, { timeout: 1200 });
  } else {
    window.setTimeout(upgrade, 500);
  }
}

function setImageSource(image, photo, sizes, fallbackUrl) {
  image.sizes = sizes;
  image.srcset = srcset(photo);
  image.src = fallbackUrl || photo.previewUrl;
}

function preloadImage(url) {
  if (!url || preloadedImages.has(url)) return;
  const image = new Image();
  image.src = url;
  preloadedImages.add(url);
}

function photoAtOffset(offset) {
  if (!renderedPhotos.length || activePhotoIndex < 0) return null;
  const index = (activePhotoIndex + offset + renderedPhotos.length) % renderedPhotos.length;
  return renderedPhotos[index];
}

function preloadLightboxNeighbors() {
  [-1, 1].forEach((offset) => {
    const photo = photoAtOffset(offset);
    if (!photo) return;
    preloadImage(photo.previewUrl);
  });
}

function openLightbox(index) {
  if (!renderedPhotos.length || index < 0) return;
  activePhotoIndex = (index + renderedPhotos.length) % renderedPhotos.length;
  const photo = renderedPhotos[activePhotoIndex];
  setImageSource(lightboxImage, photo, "min(100vw, 1080px)", photo.previewUrl);
  lightboxImage.alt = photo.caption || `${photo.location} photograph`;
  lightboxLocation.textContent = photo.location;
  lightboxDate.textContent = photo.date;
  lightbox.classList.add("open");
  document.body.style.overflow = "hidden";
  preloadLightboxNeighbors();
}

function moveLightbox(delta) {
  if (!lightbox.classList.contains("open")) return;
  openLightbox(activePhotoIndex + delta);
}

function closeViewer() {
  lightbox.classList.remove("open");
  lightboxImage.removeAttribute("src");
  lightboxImage.removeAttribute("srcset");
  lightboxImage.removeAttribute("sizes");
  activePhotoIndex = -1;
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
    let response = await fetch(INDEX_URL, { cache: "default" });
    if (!response.ok) {
      response = await fetch(API_INDEX_URL, { cache: "no-store" });
    }
    if (!response.ok) throw new Error(`HTTP ${response.status}`);
    const payload = await response.json();
    renderGallery(normalizePhotos(payload));
  } catch (error) {
    gallery.innerHTML = '<div class="empty-state">Could not load the hosted photo index.</div>';
  }
}

window.addEventListener("resize", () => {
  if (!renderedPhotos.length) return;
  const nextWidth = window.innerWidth || gallery.clientWidth || 0;
  if (Math.abs(nextWidth - layoutWidth) < 24) return;
  window.cancelAnimationFrame(resizeFrame);
  resizeFrame = window.requestAnimationFrame(() => renderGallery(renderedPhotos));
});

closeLightbox.addEventListener("click", closeViewer);
prevLightbox.addEventListener("click", () => moveLightbox(-1));
nextLightbox.addEventListener("click", () => moveLightbox(1));
lightbox.addEventListener("click", (event) => {
  if (event.target === lightbox) closeViewer();
});
document.addEventListener("keydown", (event) => {
  if (event.key === "Escape") closeViewer();
  if (event.key === "ArrowLeft") moveLightbox(-1);
  if (event.key === "ArrowRight") moveLightbox(1);
});

loadPhotos();
