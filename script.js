document.querySelectorAll('a[href^="#"]').forEach((link) => {
  link.addEventListener("click", (event) => {
    const target = document.querySelector(link.getAttribute("href"));
    if (!target) return;
    event.preventDefault();
    target.scrollIntoView({ behavior: "smooth", block: "start" });
  });
});

const colorInput = document.querySelector("#siteColor");
const resetColorButton = document.querySelector("#resetColor");
const defaultColor = "#249c9a";
const savedColor = localStorage.getItem("titResolveColor");

function hexToRgb(hex) {
  const cleanHex = hex.replace("#", "");
  const value = parseInt(cleanHex, 16);
  return {
    r: (value >> 16) & 255,
    g: (value >> 8) & 255,
    b: value & 255,
  };
}

function mixColor(hex, target, weight) {
  const color = hexToRgb(hex);
  return `rgb(${Math.round(color.r + (target.r - color.r) * weight)}, ${Math.round(
    color.g + (target.g - color.g) * weight
  )}, ${Math.round(color.b + (target.b - color.b) * weight)})`;
}

function applySiteColor(hex) {
  const root = document.documentElement;
  const dark = mixColor(hex, { r: 7, g: 48, b: 75 }, 0.48);
  const deep = mixColor(hex, { r: 7, g: 48, b: 75 }, 0.7);
  const mint = mixColor(hex, { r: 255, g: 255, b: 255 }, 0.82);

  root.style.setProperty("--teal", hex);
  root.style.setProperty("--teal-dark", dark);
  root.style.setProperty("--ink", deep);
  root.style.setProperty("--mint", mint);
  root.style.setProperty("--line", `${mixColor(hex, { r: 255, g: 255, b: 255 }, 0.62).replace("rgb", "rgba").replace(")", ", 0.45)")}`);
}

if (colorInput) {
  const startColor = savedColor || defaultColor;
  colorInput.value = startColor;
  applySiteColor(startColor);

  colorInput.addEventListener("input", (event) => {
    const color = event.target.value;
    localStorage.setItem("titResolveColor", color);
    applySiteColor(color);
  });
}

if (resetColorButton && colorInput) {
  resetColorButton.addEventListener("click", () => {
    localStorage.removeItem("titResolveColor");
    colorInput.value = defaultColor;
    applySiteColor(defaultColor);
  });
}
