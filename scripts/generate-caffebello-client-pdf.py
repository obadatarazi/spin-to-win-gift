#!/usr/bin/env python3
"""
One-page client overview PDF for Spin to Win Gift, branded for CAFFÈ BELLO.
Run from repo root: python3 scripts/generate-caffebello-client-pdf.py
"""
from __future__ import annotations

import os
import sys

from reportlab.lib.pagesizes import A4
from reportlab.lib.units import mm
from reportlab.lib.utils import ImageReader
from reportlab.pdfbase import pdfmetrics
from reportlab.pdfbase.ttfonts import TTFont
from reportlab.pdfgen import canvas

# Brand palette (aligned with Caffè Bello logo: teal + warm gold on dark)
BG = (0.04, 0.04, 0.05)
TEAL = (0.09, 0.72, 0.68)
GOLD = (0.78, 0.65, 0.38)
MUTED = (0.72, 0.68, 0.78)
WHITE = (0.96, 0.97, 0.98)

PAGE_W, PAGE_H = A4
MARGIN_X = 18 * mm
MARGIN_TOP = 14 * mm


def register_fonts() -> str:
    """Prefer a clean sans if available; fallback to Helvetica."""
    candidates = [
        "/System/Library/Fonts/Supplemental/Arial Unicode.ttf",
        "/Library/Fonts/Arial Unicode.ttf",
        "/System/Library/Fonts/Supplemental/Arial.ttf",
    ]
    for path in candidates:
        if os.path.isfile(path):
            pdfmetrics.registerFont(TTFont("BodySans", path))
            return "BodySans"
    return "Helvetica"


def wrap_lines(text: str, font: str, size: float, max_width: float, c: canvas.Canvas) -> list[str]:
    words = text.split()
    lines: list[str] = []
    line = ""
    for w in words:
        trial = (line + " " + w).strip()
        if c.stringWidth(trial, font, size) <= max_width:
            line = trial
        else:
            if line:
                lines.append(line)
            line = w
    if line:
        lines.append(line)
    return lines


def draw_bullets(
    c: canvas.Canvas,
    items: list[str],
    x: float,
    y: float,
    width: float,
    font: str,
    size: float,
    leading: float,
    color: tuple[float, float, float],
) -> float:
    c.setFont(font, size)
    c.setFillColorRGB(*color)
    yy = y
    bullet = "•"
    indent = 10
    for item in items:
        c.setFont(font, size)
        c.drawString(x, yy, bullet)
        lines = wrap_lines(item, font, size, width - indent, c)
        first = True
        for ln in lines:
            c.drawString(x + indent, yy, ln)
            yy -= leading
            first = False
        yy -= 1.5
    return yy


def main() -> int:
    root = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
    logo = os.path.join(root, "caffeBello.png")
    out = os.path.join(root, "CAFFEBELLO-Spin-to-Win-Gift-Overview.pdf")

    if not os.path.isfile(logo):
        print("Missing logo:", logo, file=sys.stderr)
        return 1

    font = register_fonts()
    font_bold = "Helvetica-Bold" if font == "Helvetica" else font

    c = canvas.Canvas(out, pagesize=A4)

    # Background
    c.setFillColorRGB(*BG)
    c.rect(0, 0, PAGE_W, PAGE_H, fill=1, stroke=0)

    # Top accent bar
    c.setFillColorRGB(*TEAL)
    c.rect(0, PAGE_H - 3 * mm, PAGE_W, 3 * mm, fill=1, stroke=0)

    # Logo
    ir = ImageReader(logo)
    iw, ih = ir.getSize()
    logo_w = 100 * mm
    scale = logo_w / iw
    logo_h = ih * scale
    lx = (PAGE_W - logo_w) / 2
    ly = PAGE_H - MARGIN_TOP - logo_h
    c.drawImage(ir, lx, ly, width=logo_w, height=logo_h, mask="auto")

    y = ly - 8 * mm

    # Kicker
    c.setFillColorRGB(*GOLD)
    c.setFont(font_bold, 9)
    c.drawCentredString(PAGE_W / 2, y, "Prepared exclusively for CAFFÈ BELLO · WooCommerce experience add-on")
    y -= 6 * mm

    # Title
    c.setFillColorRGB(*TEAL)
    c.setFont(font_bold, 20)
    c.drawCentredString(PAGE_W / 2, y, "Spin to Win Gift")
    y -= 7 * mm
    c.setFillColorRGB(*WHITE)
    c.setFont(font, 11)
    subtitle = (
        "After checkout, your customers spin a weighted prize wheel to win a real gift you define—"
        "a memorable moment that rewards loyalty and highlights your products."
    )
    for line in wrap_lines(subtitle, font, 11, PAGE_W - 2 * MARGIN_X, c):
        c.drawString(MARGIN_X, y, line)
        y -= 13
    y -= 4 * mm

    # Two columns
    col_gap = 8 * mm
    col_w = (PAGE_W - 2 * MARGIN_X - col_gap) / 2
    left_x = MARGIN_X
    right_x = MARGIN_X + col_w + col_gap
    col_top = y

    def column_header(xx: float, title: str) -> None:
        c.setFillColorRGB(*GOLD)
        c.setFont(font_bold, 11)
        c.drawString(xx, col_top, title)

    column_header(left_x, "What your customers experience")
    column_header(right_x, "What you control in WordPress")

    y_left = col_top - 5 * mm
    y_right = col_top - 5 * mm

    left_bullets = [
        "Interactive wheel on checkout and on the order thank-you page (theme-friendly hooks).",
        "Weighted segments—rarer prizes feel special, common wins keep engagement high.",
        "Real product gifts or friendly “almost” messages; winners claim securely per order.",
        "Polished popup styling and celebration feedback that you can recolor per campaign.",
    ]
    right_bullets = [
        "Multiple wheels: different cart totals, schedules, time zones, and priorities.",
        "Rules: minimum spend, item count, category, first order vs. returning, coupon, boost rules.",
        "Per-wheel themes (colors) to match CAFFÈ BELLO’s teal and gold identity.",
        "Built-in analytics plus export/import of settings for backup or staging sites.",
    ]

    y_left = draw_bullets(c, left_bullets, left_x, y_left, col_w, font, 8, 10.2, MUTED)
    y_right = draw_bullets(c, right_bullets, right_x, y_right, col_w, font, 8, 10.2, MUTED)
    y = min(y_left, y_right) - 5 * mm

    # Fit block
    c.setFillColorRGB(*TEAL)
    c.setFont(font_bold, 10)
    c.drawString(MARGIN_X, y, "Why it fits a specialty coffee brand")
    y -= 4 * mm
    c.setFillColorRGB(*WHITE)
    c.setFont(font, 9)
    fit_text = (
        "Use the wheel to spotlight new roasts, accessories, or limited releases—without juggling one-off "
        "coupon codes. Surprise first-time buyers, thank returning guests, and keep the post-purchase "
        "moment on-brand."
    )
    for line in wrap_lines(fit_text, font, 9, PAGE_W - 2 * MARGIN_X, c):
        c.drawString(MARGIN_X, y, line)
        y -= 11.5
    y -= 3 * mm

    # Requirements strip: sits below fit copy, above footer
    c.setFillColorRGB(0.12, 0.12, 0.14)
    box_h = 20 * mm
    gap = 5 * mm
    box_top = y - gap
    box_bottom = box_top - box_h
    min_bottom = 34 * mm  # leave room for footer lines
    if box_bottom < min_bottom:
        box_bottom = min_bottom
        box_top = box_bottom + box_h
    c.roundRect(MARGIN_X - 2, box_bottom, PAGE_W - 2 * MARGIN_X + 4, box_h, 3, fill=1, stroke=0)
    yy = box_top - 5 * mm
    c.setFillColorRGB(*GOLD)
    c.setFont(font_bold, 9)
    c.drawString(MARGIN_X + 3 * mm, yy, "Technical requirements")
    yy -= 4 * mm
    c.setFillColorRGB(*MUTED)
    c.setFont(font, 8.5)
    req = (
        "WordPress 6.2 or newer · WooCommerce 7.0 or newer · PHP 8.1 or newer · "
        "Plugin: Spin to Win Gift v2.2.0"
    )
    for line in wrap_lines(req, font, 8.5, PAGE_W - 2 * MARGIN_X - 6 * mm, c):
        c.drawString(MARGIN_X + 3 * mm, yy, line)
        yy -= 10.5

    # Footer
    c.setFillColorRGB(0.35, 0.33, 0.4)
    c.setFont(font, 7.5)
    footer = (
        "Developed by Obada Al Tarazi · CupCoding (cupcoding.com) · spin-to-win-gift text domain · "
        "WooCommerce → Spin to Win admin menu"
    )
    fy = 10 * mm
    for line in wrap_lines(footer, font, 7.5, PAGE_W - 2 * MARGIN_X, c):
        c.drawString(MARGIN_X, fy, line)
        fy -= 9

    c.showPage()
    c.save()
    print("Wrote:", out)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
