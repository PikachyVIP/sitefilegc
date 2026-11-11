"""Shared helpers for driving LibreOffice in headless mode."""

import os
import subprocess  # nosec B404
import sys
import tempfile
from pathlib import Path

# LibreOffice binary paths by platform
_SOFFICE_PATHS = {
    'darwin': '/Applications/LibreOffice.app/Contents/MacOS/soffice',
    'linux': '/usr/bin/soffice',
    'win32': 'C:\\Program Files\\LibreOffice\\program\\soffice.exe',
}

soffice_path = _SOFFICE_PATHS.get(sys.platform, 'soffice')


def soffice_available() -> bool:
    """Return True when the LibreOffice binary can be executed."""
    try:
        subprocess.run(  # nosec B603
            [soffice_path, '--headless', '--version'],
            check=True,
            stdout=subprocess.PIPE,
            stderr=subprocess.PIPE,
        )
        return True
    except (subprocess.CalledProcessError, FileNotFoundError):
        return False


def run_soffice(
    input_file: str,
    output_dir: str,
    lo_format: str,
    timeout: int = 120,
) -> str:
    """
    Convert ``input_file`` with ``soffice --convert-to`` and return the output path.

    Each call uses a throwaway user profile so concurrent conversions do not
    contend on LibreOffice's profile lock.
    """
    output_path = os.path.join(output_dir, f"{Path(input_file).stem}.{lo_format}")

    with tempfile.TemporaryDirectory() as user_install_dir:
        cmd = [
            soffice_path,
            '--headless',
            '--norestore',
            f'-env:UserInstallation=file://{user_install_dir}',
            '--convert-to', lo_format,
            '--outdir', output_dir,
            input_file,
        ]

        subprocess.run(  # nosec B603
            cmd,
            capture_output=True,
            text=True,
            check=True,
            timeout=timeout,
        )

    if not os.path.exists(output_path):
        raise RuntimeError(
            f"LibreOffice did not produce the expected file: {output_path}"
        )

    return output_path
