from __future__ import annotations

import html
import re
from pathlib import Path

from reportlab.lib import colors
from reportlab.lib.pagesizes import A4
from reportlab.lib.styles import ParagraphStyle, getSampleStyleSheet
from reportlab.lib.units import mm
from reportlab.platypus import (
    ListFlowable,
    ListItem,
    PageBreak,
    Paragraph,
    Preformatted,
    SimpleDocTemplate,
    Spacer,
    Table,
    TableStyle,
)

ROOT = Path(__file__).resolve().parents[1]
SOURCE = ROOT / "docs" / "guia-del-codigo-para-no-tecnicos.md"
OUTPUT = ROOT / "docs" / "guia-del-codigo-para-no-tecnicos.pdf"

NAVY = colors.HexColor("#17324D")
TEAL = colors.HexColor("#147D81")
INK = colors.HexColor("#253746")
MUTED = colors.HexColor("#5F7180")
PALE = colors.HexColor("#EAF3F5")
LINE = colors.HexColor("#D8E1E8")


def build_styles():
    styles = getSampleStyleSheet()
    styles.add(ParagraphStyle(
        name="GuideTitle", fontName="Helvetica-Bold", fontSize=27,
        leading=32, textColor=NAVY, spaceAfter=8, alignment=1,
    ))
    styles.add(ParagraphStyle(
        name="GuideSubtitle", fontName="Helvetica", fontSize=13,
        leading=19, textColor=TEAL, alignment=1, spaceAfter=14,
    ))
    styles.add(ParagraphStyle(
        name="GuideSmall", fontName="Helvetica", fontSize=8,
        leading=11, textColor=MUTED, alignment=1,
    ))
    styles.add(ParagraphStyle(
        name="GuideH1", fontName="Helvetica-Bold", fontSize=18,
        leading=23, textColor=NAVY, spaceBefore=16, spaceAfter=8,
        keepWithNext=True,
    ))
    styles.add(ParagraphStyle(
        name="GuideH2", fontName="Helvetica-Bold", fontSize=12,
        leading=16, textColor=TEAL, spaceBefore=11, spaceAfter=5,
        keepWithNext=True,
    ))
    styles.add(ParagraphStyle(
        name="GuideBody", fontName="Helvetica", fontSize=9.3,
        leading=14.4, textColor=INK, spaceAfter=7,
        allowWidows=0, allowOrphans=0,
    ))
    styles.add(ParagraphStyle(
        name="GuideTableHead", fontName="Helvetica-Bold", fontSize=8.2,
        leading=11, textColor=colors.white,
    ))
    styles.add(ParagraphStyle(
        name="GuideTableCell", fontName="Helvetica", fontSize=8.1,
        leading=11, textColor=INK,
    ))
    styles.add(ParagraphStyle(
        name="GuideCode", fontName="Courier", fontSize=8,
        leading=11, textColor=INK,
    ))
    return styles


def markup(text: str) -> str:
    safe = html.escape(text, quote=False)
    safe = re.sub(r"\*\*(.+?)\*\*", r"<b>\1</b>", safe)
    return re.sub(r"`([^`]+)`", r'<font name="Courier" size="8">\1</font>', safe)


def draw_page(canvas, document):
    canvas.saveState()
    width, height = A4
    if document.page == 1:
        canvas.setFillColor(NAVY)
        canvas.rect(0, height - 18 * mm, width, 18 * mm, fill=1, stroke=0)
        canvas.setFillColor(TEAL)
        canvas.rect(0, height - 20 * mm, width, 2 * mm, fill=1, stroke=0)
        canvas.setFillColor(PALE)
        canvas.circle(width / 2, height / 2 - 9 * mm, 37 * mm, fill=1, stroke=0)
        canvas.setFillColor(colors.HexColor("#D6ECEA"))
        canvas.circle(width / 2, height / 2 - 9 * mm, 26 * mm, fill=1, stroke=0)
        canvas.setFillColor(TEAL)
        canvas.circle(width / 2, height / 2 - 9 * mm, 15 * mm, fill=1, stroke=0)
        canvas.setFillColor(colors.white)
        canvas.setFont("Helvetica-Bold", 17)
        canvas.drawCentredString(width / 2, height / 2 - 11 * mm, "MVC")
        canvas.setStrokeColor(LINE)
        canvas.line(22 * mm, 19 * mm, width - 22 * mm, 19 * mm)
        canvas.setFillColor(MUTED)
        canvas.setFont("Helvetica", 8)
        canvas.drawCentredString(
            width / 2, 12 * mm,
            "Una guía para entender las piezas, sin necesidad de programar",
        )
    else:
        canvas.setFillColor(NAVY)
        canvas.rect(0, height - 13 * mm, width, 13 * mm, fill=1, stroke=0)
        canvas.setFillColor(colors.white)
        canvas.setFont("Helvetica-Bold", 8)
        canvas.drawString(20 * mm, height - 8.5 * mm, "SISTEMA FCT  |  GUÍA DEL CÓDIGO")
        canvas.setStrokeColor(LINE)
        canvas.line(20 * mm, 16 * mm, width - 20 * mm, 16 * mm)
        canvas.setFillColor(MUTED)
        canvas.setFont("Helvetica", 8)
        canvas.drawString(20 * mm, 10 * mm, "Guía para personas sin experiencia técnica")
        canvas.drawRightString(width - 20 * mm, 10 * mm, str(document.page))
    canvas.restoreState()


def render_document() -> None:
    styles = build_styles()
    document = SimpleDocTemplate(
        str(OUTPUT),
        pagesize=A4,
        rightMargin=20 * mm,
        leftMargin=20 * mm,
        topMargin=21 * mm,
        bottomMargin=23 * mm,
        title="Cómo entender el código de Sistema FCT",
        subject="Guía accesible de arquitectura y flujo de la aplicación",
        author="Documentación del proyecto Sistema FCT",
    )
    lines = SOURCE.read_text(encoding="utf-8").splitlines()
    story = []
    cover_done = False
    body_started = False
    index = 0

    while index < len(lines):
        current = lines[index].strip()
        if not current or current == "---":
            index += 1
            continue

        if current.startswith("# "):
            if cover_done:
                if not body_started:
                    story.append(PageBreak())
                    body_started = True
                story.append(Paragraph(markup(current[2:]), styles["GuideH1"]))
            else:
                story.extend([
                    Spacer(1, 31 * mm),
                    Paragraph(markup(current[2:]), styles["GuideTitle"]),
                ])
            index += 1
            continue

        if current.startswith("## "):
            text = markup(current[3:])
            if not cover_done:
                story.extend([
                    Spacer(1, 2 * mm),
                    Paragraph(text, styles["GuideSubtitle"]),
                    Spacer(1, 7 * mm),
                    Paragraph(
                        "Una visita guiada por las páginas, los datos y las "
                        "decisiones que hacen funcionar el sistema.",
                        styles["GuideSmall"],
                    ),
                    Spacer(1, 3 * mm),
                ])
                cover_done = True
            else:
                if not body_started:
                    story.append(PageBreak())
                    body_started = True
                story.append(Paragraph(text, styles["GuideH1"]))
            index += 1
            continue

        if current.startswith("### "):
            story.append(Paragraph(markup(current[4:]), styles["GuideH2"]))
            index += 1
            continue

        if current.startswith("```"):
            code_lines = []
            index += 1
            while index < len(lines) and not lines[index].strip().startswith("```"):
                code_lines.append(lines[index].rstrip())
                index += 1
            index += 1
            code = Preformatted("\n".join(code_lines), styles["GuideCode"])
            code_box = Table([[code]], colWidths=[160 * mm], hAlign="LEFT")
            code_box.setStyle(TableStyle([
                ("BACKGROUND", (0, 0), (-1, -1), PALE),
                ("BOX", (0, 0), (-1, -1), 0.5, LINE),
                ("LEFTPADDING", (0, 0), (-1, -1), 8),
                ("RIGHTPADDING", (0, 0), (-1, -1), 8),
                ("TOPPADDING", (0, 0), (-1, -1), 6),
                ("BOTTOMPADDING", (0, 0), (-1, -1), 6),
            ]))
            story.extend([Spacer(1, 1 * mm), code_box, Spacer(1, 2 * mm)])
            continue

        if current.startswith("|"):
            rows = []
            while index < len(lines) and lines[index].strip().startswith("|"):
                cells = [cell.strip() for cell in lines[index].strip().strip("|").split("|")]
                if not all(re.fullmatch(r":?-{3,}:?", cell) for cell in cells):
                    rows.append(cells)
                index += 1
            if rows:
                rendered_rows = []
                for row_number, row in enumerate(rows):
                    style = styles["GuideTableHead"] if row_number == 0 else styles["GuideTableCell"]
                    rendered_rows.append([Paragraph(markup(cell), style) for cell in row])
                table = Table(
                    rendered_rows,
                    colWidths=[57 * mm, 103 * mm],
                    repeatRows=1,
                    hAlign="LEFT",
                )
                table.setStyle(TableStyle([
                    ("BACKGROUND", (0, 0), (-1, 0), NAVY),
                    ("GRID", (0, 0), (-1, -1), 0.45, LINE),
                    ("VALIGN", (0, 0), (-1, -1), "TOP"),
                    ("LEFTPADDING", (0, 0), (-1, -1), 7),
                    ("RIGHTPADDING", (0, 0), (-1, -1), 7),
                    ("TOPPADDING", (0, 0), (-1, -1), 6),
                    ("BOTTOMPADDING", (0, 0), (-1, -1), 6),
                    ("ROWBACKGROUNDS", (0, 1), (-1, -1), [colors.white, PALE]),
                ]))
                story.extend([Spacer(1, 2 * mm), table, Spacer(1, 3 * mm)])
            continue

        if re.match(r"^\d+\.\s", current):
            items = []
            while index < len(lines) and re.match(r"^\d+\.\s", lines[index].strip()):
                text = re.sub(r"^\d+\.\s+", "", lines[index].strip())
                items.append(ListItem(Paragraph(markup(text), styles["GuideBody"]), leftIndent=10))
                index += 1
            story.append(ListFlowable(
                items, bulletType="1", start="1", leftIndent=17,
                bulletFontName="Helvetica", bulletFontSize=9,
                bulletColor=TEAL, spaceAfter=5,
            ))
            continue

        if current.startswith("- "):
            items = []
            while index < len(lines) and lines[index].strip().startswith("- "):
                text = lines[index].strip()[2:]
                items.append(ListItem(Paragraph(markup(text), styles["GuideBody"]), leftIndent=10))
                index += 1
            story.append(ListFlowable(
                items, bulletType="bullet", start="circle", leftIndent=16,
                bulletFontName="Helvetica", bulletFontSize=7,
                bulletColor=TEAL, spaceAfter=5,
            ))
            continue

        paragraph_lines = [current]
        index += 1
        while index < len(lines):
            following = lines[index].strip()
            if (
                not following
                or following == "---"
                or following.startswith("#")
                or following.startswith("|")
                or following.startswith("- ")
                or re.match(r"^\d+\.\s", following)
            ):
                break
            paragraph_lines.append(following)
            index += 1
        story.append(Paragraph(markup(" ".join(paragraph_lines)), styles["GuideBody"]))

    story.append(Spacer(1, 2 * mm))
    story.append(Paragraph(
        "Documento de orientación basado en el código del repositorio y en su "
        "README. Si una instrucción operativa cambia, consulta el README actualizado.",
        styles["GuideSmall"],
    ))
    document.build(story, onFirstPage=draw_page, onLaterPages=draw_page)
    print(f"PDF generado: {OUTPUT} ({OUTPUT.stat().st_size:,} bytes)")


if __name__ == "__main__":
    render_document()
