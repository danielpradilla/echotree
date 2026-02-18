const DEFAULT_BASE_URL = "https://your-domain.com";

function normalizeBaseUrl(raw) {
  const value = (raw || "").trim();
  if (!value) return DEFAULT_BASE_URL;
  return value.replace(/\/+$/, "");
}

function setError(message) {
  const el = document.getElementById("error");
  el.textContent = message;
  el.style.display = "block";
}

async function getActiveTab() {
  const tabs = await chrome.tabs.query({ active: true, currentWindow: true });
  return tabs && tabs.length > 0 ? tabs[0] : null;
}

function isShareableUrl(url) {
  return typeof url === "string" && /^https?:\/\//i.test(url);
}

(async function init() {
  const urlEl = document.getElementById("current-url");
  const openBtn = document.getElementById("open-share");

  const tab = await getActiveTab();
  const tabUrl = tab && tab.url ? tab.url : "";
  urlEl.textContent = tabUrl || "No active tab URL found.";

  if (!isShareableUrl(tabUrl)) {
    openBtn.disabled = true;
    setError("This tab URL cannot be shared. Open a normal http(s) page.");
    return;
  }

  openBtn.addEventListener("click", async () => {
    const stored = await chrome.storage.sync.get(["echotree_base_url"]);
    const baseUrl = normalizeBaseUrl(stored.echotree_base_url);
    const shareUrl = `${baseUrl}/share?url=${encodeURIComponent(tabUrl)}`;
    await chrome.tabs.create({ url: shareUrl });
    window.close();
  });
})();
