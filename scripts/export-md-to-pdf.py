from __future__ import annotations

import argparse
import os
import shutil
import subprocess
import sys
import time
import uuid
from pathlib import Path
from typing import Optional, Tuple


PROJECT_ROOT = Path(__file__).resolve().parents[1]
DEFAULT_INPUT = PROJECT_ROOT / "README.md"
DEFAULT_CSS = PROJECT_ROOT / "docs_md" / "readme-pdf.css"


def find_executable(
    names: Tuple[str, ...], platform_paths: Tuple[Path, ...] = ()
) -> Optional[str]:
    for name in names:
        executable = shutil.which(name)
        if executable:
            return executable

    for path in platform_paths:
        if path.is_file():
            return str(path)
    return None


def find_pandoc() -> str:
    platform_paths = ()
    if sys.platform == "win32":
        local_app_data = Path(os.environ.get("LOCALAPPDATA", ""))
        platform_paths = (local_app_data / "Pandoc" / "pandoc.exe",)

    pandoc = find_executable(("pandoc", "pandoc.exe"), platform_paths)
    if pandoc is None:
        raise RuntimeError(
            "Pandoc was not found. Install Pandoc and make sure it is available on PATH."
        )
    return pandoc


def find_browser() -> str:
    if sys.platform == "win32":
        local_app_data = Path(os.environ.get("LOCALAPPDATA", ""))
        program_files = Path(os.environ.get("ProgramFiles", ""))
        program_files_x86 = Path(os.environ.get("ProgramFiles(x86)", ""))
        candidates = (
            local_app_data / "BraveSoftware/Brave-Browser/Application/brave.exe",
            program_files / "Microsoft/Edge/Application/msedge.exe",
            program_files_x86 / "Microsoft/Edge/Application/msedge.exe",
            program_files / "Google/Chrome/Application/chrome.exe",
            program_files_x86 / "Google/Chrome/Application/chrome.exe",
        )
    elif sys.platform == "darwin":
        candidates = (
            Path("/Applications/Google Chrome.app/Contents/MacOS/Google Chrome"),
            Path("/Applications/Microsoft Edge.app/Contents/MacOS/Microsoft Edge"),
            Path("/Applications/Brave Browser.app/Contents/MacOS/Brave Browser"),
        )
    else:
        candidates = ()

    browser = find_executable(
        (
            "brave",
            "brave-browser",
            "microsoft-edge",
            "msedge",
            "google-chrome",
            "google-chrome-stable",
            "chromium",
            "chromium-browser",
            "chrome",
        ),
        candidates,
    )
    if browser is None:
        raise RuntimeError(
            "No Chromium-based browser was found. Install Chrome, Edge, Brave, or "
            "Chromium and make sure it is available on PATH."
        )
    return browser


def run(args: argparse.Namespace) -> Path:
    input_path = args.input.expanduser().resolve()
    css_path = args.css.expanduser().resolve()
    output_path = (
        args.output.expanduser().resolve()
        if args.output is not None
        else input_path.with_suffix(".pdf")
    )

    if not input_path.is_file():
        raise RuntimeError(f"Markdown input was not found: {input_path}")
    if not css_path.is_file():
        raise RuntimeError(f"Stylesheet was not found: {css_path}")
    if input_path == output_path:
        raise RuntimeError("The output PDF path must not be the Markdown input path.")

    pandoc = find_pandoc()
    browser = find_browser()
    output_path.parent.mkdir(parents=True, exist_ok=True)

    suffix = uuid.uuid4().hex
    html_path = output_path.parent / f".md-to-pdf-{suffix}.html"
    working_pdf_path = output_path.parent / f".md-to-pdf-{suffix}.pdf"
    stylesheet_uri = css_path.as_uri()

    try:
        conversion = subprocess.run(
            [
                pandoc,
                str(input_path),
                "--standalone",
                "--from=gfm",
                f"--css={stylesheet_uri}",
                "--metadata=title:",
                f"--output={html_path}",
            ],
            check=False,
            capture_output=True,
            text=True,
        )
        if conversion.returncode != 0 or not html_path.is_file():
            detail = conversion.stderr.strip() or conversion.stdout.strip()
            message = f"Pandoc could not generate printable HTML (exit code {conversion.returncode})."
            if detail:
                message += "\n" + detail
            raise RuntimeError(
                message
            )

        rendering = subprocess.run(
            [
                browser,
                "--headless",
                "--disable-gpu",
                "--no-first-run",
                "--no-pdf-header-footer",
                f"--print-to-pdf={working_pdf_path}",
                html_path.resolve().as_uri(),
            ],
            check=False,
            capture_output=True,
            text=True,
        )
        deadline = time.monotonic() + 30
        while not working_pdf_path.is_file() and time.monotonic() < deadline:
            time.sleep(0.25)

        if (
            rendering.returncode != 0
            or not working_pdf_path.is_file()
            or working_pdf_path.stat().st_size < 1000
        ):
            detail = rendering.stderr.strip() or rendering.stdout.strip()
            message = f"The browser could not generate the PDF (exit code {rendering.returncode})."
            if detail:
                message += "\n" + detail
            raise RuntimeError(
                message
            )

        os.replace(working_pdf_path, output_path)
        return output_path
    finally:
        html_path.unlink(missing_ok=True)
        working_pdf_path.unlink(missing_ok=True)


def main() -> int:
    parser = argparse.ArgumentParser(
        description="Export a Markdown file to PDF using Pandoc and a Chromium browser."
    )
    parser.add_argument(
        "--input",
        type=Path,
        default=DEFAULT_INPUT,
        help=f"Markdown source (default: {DEFAULT_INPUT})",
    )
    parser.add_argument(
        "--output",
        type=Path,
        help="PDF destination (default: the input path with a .pdf suffix)",
    )
    parser.add_argument(
        "--css",
        type=Path,
        default=DEFAULT_CSS,
        help=f"Print stylesheet (default: {DEFAULT_CSS})",
    )
    args = parser.parse_args()

    try:
        output_path = run(args)
    except (OSError, RuntimeError) as error:
        print(f"PDF export failed: {error}", file=sys.stderr)
        return 1

    print(f"PDF generated: {output_path}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
