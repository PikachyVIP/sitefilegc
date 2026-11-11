import logging
import os
from pathlib import Path
from typing import Optional

from .converter_interface import ConverterInterface


class PDF2DOCXConverter(ConverterInterface):
    """Convert PDF-family inputs into DOCX using the pdf2docx package."""

    supported_input_formats: set = {
        'pdf',
        'pdf/a',
        'pdf/x',
        'pdf/e',
        'pdf/ua',
        'pdf/vt',
    }
    supported_output_formats: set = {
        'docx',
    }

    def __init__(self, input_file: str, output_dir: str, input_type: str, output_type: str):
        super().__init__(input_file, output_dir, input_type, output_type)

    @staticmethod
    def _load_pdf_converter_class():
        try:
            from pdf2docx import Converter
        except ImportError as exc:
            raise RuntimeError(
                "pdf2docx is not installed; PDF to DOCX conversion is unavailable."
            ) from exc

        return Converter

    @classmethod
    def can_register(cls) -> bool:
        try:
            cls._load_pdf_converter_class()
            return True
        except RuntimeError:
            return False

    def can_convert(self) -> bool:
        input_fmt = self.input_type.lower()
        output_fmt = self.output_type.lower()

        if input_fmt not in self.supported_input_formats:
            return False
        if output_fmt not in self.supported_output_formats:
            return False

        return True

    @classmethod
    def get_formats_compatible_with(cls, format_type: str) -> set:
        fmt = format_type.lower()
        if fmt not in cls.supported_input_formats:
            return set()
        return cls.supported_output_formats.copy()

    @staticmethod
    def _run_quietly(func, *args, **kwargs):
        previous_disable_level = logging.root.manager.disable
        logging.disable(logging.INFO)

        try:
            return func(*args, **kwargs)
        finally:
            logging.disable(previous_disable_level)

    def convert(self, overwrite: bool = True, quality: Optional[str] = None) -> list[str]:
        """Convert the input PDF into a DOCX file."""
        del quality

        if not self.can_convert():
            raise ValueError(
                f"Conversion from {self.input_type} to {self.output_type} is not supported."
            )

        if not os.path.isfile(self.input_file):
            raise FileNotFoundError(f"Input file not found: {self.input_file}")

        input_filename = Path(self.input_file).stem
        output_file = os.path.join(self.output_dir, f"{input_filename}.docx")

        if not overwrite and os.path.exists(output_file):
            return [output_file]

        converter_class = self._load_pdf_converter_class()
        converter = None

        try:
            converter = converter_class(self.input_file)
            self._run_quietly(converter.convert, output_file)
        except (ValueError, RuntimeError, FileNotFoundError):
            raise
        except Exception as exc:
            raise RuntimeError(f"PDF to DOCX conversion failed: {exc}") from exc
        finally:
            if converter is not None:
                converter.close()

        if not os.path.exists(output_file):
            raise RuntimeError(f"Output file was not created: {output_file}")

        return [output_file]