const HOST = "https://photography.pulkith.com";
const INDEX_URL = `${HOST}/admin/api.php?action=list`;

const gallery = document.querySelector("#gallery");
const heroImage = document.querySelector("#heroImage");
const lightbox = document.querySelector("#lightbox");
const lightboxImage = document.querySelector("#lightboxImage");
const lightboxLocation = document.querySelector("#lightboxLocation");
const lightboxDate = document.querySelector("#lightboxDate");
const closeLightbox = document.querySelector("#closeLightbox");

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
    .sort((a, b) => a.locationIndex - b.locationIndex || b.priority - a.priority);
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

function tileClass(photo) {
  const hash = hashString(`${photo.id}-${photo.locationIndex}`);
  if (photo.priority >= 9) return "feature";
  if (photo.priority >= 7 || hash % 5 === 0) return "wide";
  if (hash % 3 === 0) return "tall";
  return "";
}

function estimatedSpan(photo, className) {
  const priorityBoost = photo.priority >= 8 ? 10 : photo.priority >= 6 ? 5 : 0;
  if (className === "feature") return 54 + priorityBoost;
  if (className === "wide") return 39 + priorityBoost;
  if (className === "tall") return 56;
  return 42 + Math.round(priorityBoost / 2);
}

function renderGallery(photos) {
  gallery.innerHTML = "";
  if (!photos.length) {
    gallery.innerHTML = '<div class="empty-state" style="grid-column: 1 / -1;">Upload photos from /admin to populate the portfolio.</div>';
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

  photos.forEach((photo) => {
    const className = tileClass(photo);
    const button = document.createElement("button");
    button.type = "button";
    button.className = `photo-tile ${className}`.trim();
    button.style.gridRowEnd = `span ${estimatedSpan(photo, className)}`;
    const sourceUrl = className === "feature" ? photo.displayUrl : photo.previewUrl;
    button.innerHTML = `
      <img src="${sourceUrl}" alt="${escapeHtml(photo.caption || `${photo.location} photograph`)}" loading="lazy" decoding="async">
      <span class="photo-meta">
        <span>${escapeHtml(photo.location)}</span>
        <span>${escapeHtml(photo.date)}</span>
      </span>
    `;
    button.addEventListener("click", () => openLightbox(photo));
    gallery.appendChild(button);
    observer.observe(button);
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
    gallery.innerHTML = '<div class="empty-state" style="grid-column: 1 / -1;">Could not load the hosted photo index.</div>';
  }
}

closeLightbox.addEventListener("click", closeViewer);
lightbox.addEventListener("click", (event) => {
  if (event.target === lightbox) closeViewer();
});
document.addEventListener("keydown", (event) => {
  if (event.key === "Escape") closeViewer();
});

loadPhotos();
