import os
import subprocess  # nosec B404
from pathlib import Path
from typing import Optional

from core import validate_safe_path

from . import _libreoffice
from .converter_interface import ConverterInterface


class IWorkConverter(ConverterInterface):
    """
    Converter for Apple iWork word-processing and spreadsheet documents.

    Uses LibreOffice headless, which reads Apple Pages and Numbers files via
    its libetonyek import filters. Those filters are import-only: LibreOffice
    has no iWork export filter, so conversions into ``.pages``/``.numbers``
    are not offered.

    Apple Keynote (``.key``) is handled by :class:`LibreOfficeConverter`,
    which shares the presentation pipeline with PPTX/ODP.
    """

    # Output formats reachable from each iWork input. Pages loads in Writer
    # and Numbers in Calc, so the two inputs have disjoint targets.
    _output_formats_by_input: dict[str, set[str]] = {
        'pages': {'docx', 'doc', 'odt', 'rtf', 'pdf', 'html', 'txt'},
        'numbers': {'xlsx', 'xls', 'ods', 'csv', 'pdf', 'html'},
    }

    supported_input_formats: set = set(_output_formats_by_input)
    supported_output_formats: set = set().union(*_output_formats_by_input.values())

    def __init__(self, input_file: str, output_dir: str, input_type: str, output_type: str):
        super().__init__(input_file, output_dir, input_type, output_type)

    @classmethod
    def can_register(cls) -> bool:
        return _libreoffice.soffice_available()

    def can_convert(self) -> bool:
        return self.output_type in self._output_formats_by_input.get(self.input_type, set())

    @classmethod
    def get_formats_compatible_with(cls, format_type: str) -> set:
        return set(cls._output_formats_by_input.get(format_type.lower(), set()))

    def convert(self, overwrite: bool = True, quality: Optional[str] = None) -> list[str]:
        """
        Convert the input iWork document.

        Args:
            overwrite: Whether to overwrite an existing output file.
            quality: Not applicable for iWork conversions, ignored.

        Returns:
            List containing the path to the converted output file.
        """
        if not self.can_convert():
            raise ValueError(
                f"Conversion from {self.input_type} to {self.output_type} "
                "is not supported."
            )

        if not os.path.isfile(self.input_file):
            raise FileNotFoundError(f"Input file not found: {self.input_file}")

        output_file = os.path.join(
            self.output_dir, f"{Path(self.input_file).stem}.{self.output_type}"
        )

        if not overwrite and os.path.exists(output_file):
            return [output_file]

        validate_safe_path(self.input_file)
        validate_safe_path(output_file)

        try:
            _libreoffice.run_soffice(self.input_file, self.output_dir, self.output_type)
        except subprocess.CalledProcessError as e:
            raise RuntimeError(
                f"LibreOffice conversion failed: {e.stderr or e.stdout or str(e)}"
            )
        except (ValueError, RuntimeError):
            raise
        except Exception as e:
            raise RuntimeError(f"iWork conversion failed: {str(e)}")

        return [output_file]
