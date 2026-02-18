const DEFAULT_BASE_URL = "https://your-domain.com";

function normalizeBaseUrl(raw) {
  const value = (raw || "").trim();
  if (!value) return "";
  return value.replace(/\/+$/, "");
}

function isValidBaseUrl(url) {
  try {
    const parsed = new URL(url);
    return parsed.protocol === "https:" || parsed.protocol === "http:";
  } catch {
    return false;
  }
}

(async function init() {
  const input = document.getElementById("base-url");
  const save = document.getElementById("save");
  const status = document.getElementById("status");

  const stored = await chrome.storage.sync.get(["echotree_base_url"]);
  input.value = stored.echotree_base_url || DEFAULT_BASE_URL;

  save.addEventListener("click", async () => {
    const normalized = normalizeBaseUrl(input.value);
    if (!isValidBaseUrl(normalized)) {
      status.textContent = "Please enter a valid http(s) base URL.";
      status.style.color = "#b00020";
      return;
    }

    await chrome.storage.sync.set({ echotree_base_url: normalized });
    status.textContent = "Saved.";
    status.style.color = "#2e7d32";
  });
})();
