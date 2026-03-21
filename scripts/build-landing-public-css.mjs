/**
 * Rebuild css/landing-public.css from layout + dark-mode + landing.
 * Run: npm run build:landing-css
 */
import fs from "node:fs";
import path from "node:path";
import { fileURLToPath } from "node:url";

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const root = path.resolve(__dirname, "..");
const cssDir = path.join(root, "css");

const tokenBlock = `/* landing-public.css — merged for public landing (one RTT). Theme tokens until async Bootstrap loads. */
:root,[data-bs-theme=light]{--bs-blue:#0d6efd;--bs-primary:#0d6efd;--bs-body-bg:#fff;--bs-body-color:#212529;--bs-secondary-color:rgba(33,37,41,.75);--bs-secondary-bg:#e9ecef;--bs-border-color:#dee2e6;--bs-primary-border-subtle:#9ec5fe;--bs-light:#f8f9fa;--bs-dark:#212529}
html[data-bs-theme=dark]{--bs-blue:#6ea8fe;--bs-primary:#6ea8fe;--bs-body-bg:#111;--bs-body-color:#eee;--bs-secondary-color:rgba(238,238,238,.75);--bs-secondary-bg:#2b3035;--bs-border-color:#495057;--bs-primary-border-subtle:#084298;--bs-light:#343a40;--bs-dark:#f8f9fa}
`;

const pieces = ["layout.css", "dark-mode.css", "landing.css"].map((name) =>
	fs.readFileSync(path.join(cssDir, name), "utf8")
);

const out = path.join(cssDir, "landing-public.css");
fs.writeFileSync(out, `${tokenBlock}\n${pieces.join("\n")}\n`, "utf8");
console.log("Wrote", path.relative(root, out));
