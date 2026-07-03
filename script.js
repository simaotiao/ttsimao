document.querySelectorAll('a[href^="#"]').forEach((link) => {
  link.addEventListener("click", (event) => {
    const target = document.querySelector(link.getAttribute("href"));
    if (!target) return;
    event.preventDefault();
    target.scrollIntoView({ behavior: "smooth", block: "start" });
  });
});

const fallbackConfig = {
  whatsappNumber: "5511930756726",
  carousel: [
    { src: "./assets/hero-servicos-principal-web.jpg", alt: "Ambiente de trabalho TiT Resolve" },
    { src: "./assets/hero-casal-fisionomia-web.jpg", alt: "Casal TiT Resolve em desenho" },
    { src: "./assets/casal-trabalhando-desenho-web.jpg", alt: "Servicos residenciais em desenho" },
    { src: "./assets/casal-trabalhando-web.jpg", alt: "Casal trabalhando" },
    { src: "./assets/hero-personagens-oficiais-web.jpg", alt: "Personagens TiT Resolve" },
    { src: "./assets/tit-resolve-casal-web.jpg", alt: "TiT Resolve casal de aluguel" },
    { src: "./assets/boneca-obra-web.jpg", alt: "Profissional de obra" },
    { src: "./assets/boneca-andando-web.jpg", alt: "Profissional em movimento" },
    { src: "./assets/hero-casal-final-web.jpg", alt: "Casal TiT Resolve" },
    { src: "./assets/hero-servicos-principal-original.png", alt: "Divulgacao dos servicos TiT Resolve" },
  ],
  promo: {
    enabled: true,
    eyebrow: "Oferta especial por tempo limitado",
    title: "Pintura completa em apartamento da COHAB",
    price: "R$ 799,00",
    text:
      "Renove seu apartamento com uma pintura completa, organizada e feita com capricho pela TiT Resolve. Uma solucao ideal para deixar os ambientes mais claros, limpos e com cara de casa nova, com atendimento direto e combinacao simples pelo WhatsApp.",
    note: "Na cor branca. Valor promocional sujeito a avaliacao do estado das paredes, metragem do imovel e necessidade de reparos extras.",
    buttonText: "Quero pintar meu apartamento",
    whatsappText: "Ola! Quero saber sobre a promocao de pintura completa em apartamento da COHAB por R$ 799,00 na cor branca.",
  },
};

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

function buildWhatsappUrl(number, text) {
  return `https://wa.me/${number}?text=${encodeURIComponent(text)}`;
}

function renderCarousel(items) {
  const track = document.querySelector("#carouselTrack");
  if (!track) return;

  const slides = items.slice(0, 10);
  track.innerHTML = slides
    .map(
      (item, index) => `
        <figure class="carousel-slide">
          <img src="${item.src}" alt="${item.alt || `Servico TiT Resolve ${index + 1}`}" loading="lazy" />
        </figure>
      `
    )
    .join("");

  let index = 0;
  const previous = document.querySelector(".carousel-button.prev");
  const next = document.querySelector(".carousel-button.next");

  function visibleCount() {
    if (window.matchMedia("(max-width: 560px)").matches) return 1;
    if (window.matchMedia("(max-width: 920px)").matches) return 2;
    return 3;
  }

  function updateCarousel() {
    const slide = track.querySelector(".carousel-slide");
    if (!slide) return;
    const gap = parseFloat(getComputedStyle(track).gap) || 0;
    const step = slide.getBoundingClientRect().width + gap;
    const max = Math.max(0, slides.length - visibleCount());
    index = Math.min(index, max);
    track.style.transform = `translateX(${-index * step}px)`;
  }

  previous?.addEventListener("click", () => {
    index = Math.max(0, index - 1);
    updateCarousel();
  });

  next?.addEventListener("click", () => {
    index = Math.min(Math.max(0, slides.length - visibleCount()), index + 1);
    updateCarousel();
  });

  window.addEventListener("resize", updateCarousel);
  setInterval(() => {
    const max = Math.max(0, slides.length - visibleCount());
    index = index >= max ? 0 : index + 1;
    updateCarousel();
  }, 4500);
  updateCarousel();
}

function renderPromo(config) {
  const modal = document.querySelector("#promoModal");
  const promo = config.promo || fallbackConfig.promo;
  if (!modal || !promo.enabled) return;
  modal.dataset.promoReady = "1";

  const number = config.whatsappNumber || fallbackConfig.whatsappNumber;
  document.querySelector("#promoEyebrow").textContent = promo.eyebrow;
  document.querySelector("#promoTitle").textContent = promo.title;
  document.querySelector("#promoPrice").textContent = promo.price;
  document.querySelector("#promoText").textContent = promo.text;
  document.querySelector("#promoNote").textContent = promo.note;
  document.querySelector("#promoButton").textContent = promo.buttonText || "Quero essa promocao";
  document.querySelector("#promoButton").href = buildWhatsappUrl(number, promo.whatsappText || promo.title);

  const close = document.querySelector("#promoClose");
  const closePromo = () => {
    modal.classList.remove("is-open");
    modal.setAttribute("aria-hidden", "true");
  };

  close?.addEventListener("click", closePromo);
  modal.addEventListener("click", (event) => {
    if (event.target === modal) closePromo();
  });
  window.addEventListener("keydown", (event) => {
    if (event.key === "Escape") closePromo();
  });

  window.setTimeout(() => {
    modal.classList.add("is-open");
    modal.setAttribute("aria-hidden", "false");
    window.setTimeout(closePromo, 7000);
  }, 700);
}

function updateWhatsappLinks(number) {
  document.querySelectorAll('a[href*="wa.me/"]').forEach((link) => {
    link.href = link.href.replace(/wa\.me\/\d+/, `wa.me/${number}`);
  });
}

async function loadSiteConfig() {
  try {
    const response = await fetch(`./site-config.json?v=${Date.now()}`, { cache: "no-store" });
    if (!response.ok) throw new Error("Config unavailable");
    return { ...fallbackConfig, ...(await response.json()) };
  } catch {
    return fallbackConfig;
  }
}

loadSiteConfig().then((config) => {
  updateWhatsappLinks(config.whatsappNumber || fallbackConfig.whatsappNumber);
  renderCarousel(config.carousel || fallbackConfig.carousel);
  renderPromo(config);
});
