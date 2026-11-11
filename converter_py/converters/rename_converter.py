import logging
import shutil

from pathlib import Path
from typing import Optional

from .converter_interface import ConverterInterface

logger = logging.getLogger(__name__)

# Maps each format to the set of formats it can be renamed to.
_RENAME_MAP: dict[str, set[str]] = {
    'zip': {'cbz'},
    'cbz': {'zip'},
    'rar': {'cbr'},
    'cbr': {'rar'},
    '7z': {'cb7'},
    'cb7': {'7z'},
}

_ALL_FORMATS: set[str] = set(_RENAME_MAP.keys())


class RenameConverter(ConverterInterface):
    """
    Converter that converts by simply changing the file extension.
    zip <-> cbz, rar <-> cbr, 7z <-> cb7.
    """

    supported_input_formats: set = _ALL_FORMATS
    supported_output_formats: set = _ALL_FORMATS

    def __init__(self, input_file: str, output_dir: str, input_type: str, output_type: str):
        super().__init__(input_file, output_dir, input_type, output_type)

    def can_convert(self) -> bool:
        return self.output_type in _RENAME_MAP.get(self.input_type, set())

    @classmethod
    def get_formats_compatible_with(cls, format_type: str) -> set:
        fmt = format_type.lower()
        return _RENAME_MAP.get(fmt, set()).copy()

    def convert(self, overwrite: bool = True, quality: Optional[str] = None) -> list[str]:
        input_path = Path(self.input_file)
        output_path = Path(self.output_dir) / f"{input_path.stem}.{self.output_type}"

        if output_path.exists() and not overwrite:
            logger.info("Output file already exists, skipping: %s", output_path)
            return [str(output_path)]

        shutil.copy2(str(input_path), str(output_path))
        logger.info("Renamed %s -> %s", input_path.name, output_path.name)
        return [str(output_path)]