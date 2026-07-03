const canvas = document.querySelector("#paintCanvas");
if (!canvas) {
  throw new Error("Simulador de pintura nao encontrado nesta pagina.");
}

const ctx = canvas.getContext("2d");
const photoInput = document.querySelector("#photoInput");
const colorInput = document.querySelector("#paintColor");
const opacityInput = document.querySelector("#paintOpacity");
const brushInput = document.querySelector("#brushSize");
const autoButton = document.querySelector("#autoWall");
const paintButton = document.querySelector("#paintMode");
const eraseButton = document.querySelector("#eraseMode");
const clearButton = document.querySelector("#clearMask");
const downloadButton = document.querySelector("#downloadResult");
const emptyState = document.querySelector("#canvasEmpty");

let originalImage = null;
let originalPixels = null;
let maskCanvas = document.createElement("canvas");
let maskCtx = maskCanvas.getContext("2d");
let mode = "paint";
let isDrawing = false;
let imageRect = { x: 0, y: 0, width: canvas.width, height: canvas.height };

function resizeCanvasToContainer() {
  const rect = canvas.getBoundingClientRect();
  const ratio = window.devicePixelRatio || 1;
  canvas.width = Math.max(600, Math.round(rect.width * ratio));
  canvas.height = Math.max(360, Math.round(rect.height * ratio));
  maskCanvas.width = canvas.width;
  maskCanvas.height = canvas.height;
  draw();
}

function fitImage(image) {
  const scale = Math.min(canvas.width / image.width, canvas.height / image.height);
  const width = image.width * scale;
  const height = image.height * scale;
  return {
    x: (canvas.width - width) / 2,
    y: (canvas.height - height) / 2,
    width,
    height,
  };
}

function hexToRgb(hex) {
  const value = parseInt(hex.replace("#", ""), 16);
  return {
    r: (value >> 16) & 255,
    g: (value >> 8) & 255,
    b: value & 255,
  };
}

function draw() {
  ctx.clearRect(0, 0, canvas.width, canvas.height);
  if (!originalImage) return;

  imageRect = fitImage(originalImage);
  ctx.drawImage(originalImage, imageRect.x, imageRect.y, imageRect.width, imageRect.height);

  const base = ctx.getImageData(0, 0, canvas.width, canvas.height);
  originalPixels = new ImageData(new Uint8ClampedArray(base.data), base.width, base.height);
  const mask = maskCtx.getImageData(0, 0, canvas.width, canvas.height);
  const color = hexToRgb(colorInput.value);
  const opacity = Number(opacityInput.value) / 100;

  for (let i = 0; i < base.data.length; i += 4) {
    const maskAlpha = mask.data[i + 3] / 255;
    if (!maskAlpha) continue;
    const strength = opacity * maskAlpha;
    const light = (base.data[i] + base.data[i + 1] + base.data[i + 2]) / 765;
    base.data[i] = base.data[i] * (1 - strength) + color.r * strength * (0.72 + light * 0.28);
    base.data[i + 1] = base.data[i + 1] * (1 - strength) + color.g * strength * (0.72 + light * 0.28);
    base.data[i + 2] = base.data[i + 2] * (1 - strength) + color.b * strength * (0.72 + light * 0.28);
  }

  ctx.putImageData(base, 0, 0);
}

function loadImage(file) {
  const reader = new FileReader();
  reader.onload = () => {
    const image = new Image();
    image.onload = () => {
      originalImage = image;
      maskCtx.clearRect(0, 0, maskCanvas.width, maskCanvas.height);
      draw();
      emptyState.classList.add("is-hidden");
    };
    image.src = reader.result;
  };
  reader.readAsDataURL(file);
}

function getCanvasPoint(event) {
  const rect = canvas.getBoundingClientRect();
  const pointer = event.touches ? event.touches[0] : event;
  return {
    x: ((pointer.clientX - rect.left) / rect.width) * canvas.width,
    y: ((pointer.clientY - rect.top) / rect.height) * canvas.height,
  };
}

function paintAt(point) {
  if (!originalImage) return;
  maskCtx.save();
  maskCtx.globalCompositeOperation = mode === "erase" ? "destination-out" : "source-over";
  maskCtx.fillStyle = "rgba(0,0,0,1)";
  maskCtx.beginPath();
  maskCtx.arc(point.x, point.y, Number(brushInput.value) * (window.devicePixelRatio || 1), 0, Math.PI * 2);
  maskCtx.fill();
  maskCtx.restore();
  draw();
}

function detectLightWall() {
  if (!originalImage || !originalPixels) return;
  const data = originalPixels.data;
  const mask = maskCtx.createImageData(canvas.width, canvas.height);

  for (let y = 0; y < canvas.height; y++) {
    for (let x = 0; x < canvas.width; x++) {
      const i = (y * canvas.width + x) * 4;
      const insideImage =
        x >= imageRect.x &&
        x <= imageRect.x + imageRect.width &&
        y >= imageRect.y &&
        y <= imageRect.y + imageRect.height;
      if (!insideImage) continue;

      const r = data[i];
      const g = data[i + 1];
      const b = data[i + 2];
      const max = Math.max(r, g, b);
      const min = Math.min(r, g, b);
      const brightness = (r + g + b) / 3;
      const saturation = max - min;
      const likelyWall = brightness > 118 && saturation < 78;
      if (likelyWall) {
        mask.data[i] = 0;
        mask.data[i + 1] = 0;
        mask.data[i + 2] = 0;
        mask.data[i + 3] = 210;
      }
    }
  }

  maskCtx.putImageData(mask, 0, 0);
  draw();
}

photoInput.addEventListener("change", (event) => {
  const file = event.target.files?.[0];
  if (file) loadImage(file);
});

canvas.addEventListener("pointerdown", (event) => {
  isDrawing = true;
  canvas.setPointerCapture(event.pointerId);
  paintAt(getCanvasPoint(event));
});

canvas.addEventListener("pointermove", (event) => {
  if (!isDrawing) return;
  paintAt(getCanvasPoint(event));
});

canvas.addEventListener("pointerup", () => {
  isDrawing = false;
});

canvas.addEventListener("pointercancel", () => {
  isDrawing = false;
});

colorInput.addEventListener("input", draw);
opacityInput.addEventListener("input", draw);

paintButton.addEventListener("click", () => {
  mode = "paint";
  paintButton.classList.add("is-active");
  eraseButton.classList.remove("is-active");
});

eraseButton.addEventListener("click", () => {
  mode = "erase";
  eraseButton.classList.add("is-active");
  paintButton.classList.remove("is-active");
});

autoButton.addEventListener("click", detectLightWall);

clearButton.addEventListener("click", () => {
  maskCtx.clearRect(0, 0, maskCanvas.width, maskCanvas.height);
  draw();
});

downloadButton.addEventListener("click", () => {
  if (!originalImage) return;
  const link = document.createElement("a");
  link.download = "simulacao-pintura-tit-resolve.png";
  link.href = canvas.toDataURL("image/png");
  link.click();
});

window.addEventListener("resize", resizeCanvasToContainer);
paintButton.classList.add("is-active");
resizeCanvasToContainer();
