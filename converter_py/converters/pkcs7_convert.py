import base64
import logging
import os
from pathlib import Path
from typing import Optional

from asn1crypto import cms

from .converter_interface import ConverterInterface

logger = logging.getLogger(__name__)


class PKCS7Converter(ConverterInterface):
    """
    Converter for extracting original content from PKCS#7/CMS signed containers (.p7m).

    P7M is a standard PKCS#7 SignedData envelope format commonly used in Italy
    and other EU countries for digitally signed documents. This converter extracts
    the encapsulated content (e.g., PDF, XML) from the signed container without
    performing signature verification.
    """

    supported_input_formats: set = {
        'p7m',
    }

    # Output formats are determined dynamically from the embedded content;
    # this set is intentionally empty.
    supported_output_formats: set = set()

    @classmethod
    def get_formats_compatible_with(cls, format_type: str) -> set:
        """
        Return the detected inner content type as the sole compatible output.

        For compound types like ``p7m/pdf``, returns ``{"pdf"}``.
        For plain ``p7m`` (inner type unknown), returns an empty set.
        """
        lower = format_type.lower()
        if lower.startswith('p7m/') and '/' in lower:
            inner = lower.split('/', 1)[1]
            return {inner}
        return set()

    def can_convert(self) -> bool:
        # Accept any output type — the converter simply extracts the
        # embedded bytes regardless of format.
        base_input = self.input_type.split('/')[0] if '/' in self.input_type else self.input_type
        return base_input in self.supported_input_formats

    def convert(self, overwrite: bool = True, quality: Optional[str] = None) -> list[str]:
        input_path = Path(self.input_file)
        base_name = input_path.stem
        output_path = os.path.join(self.output_dir, f"{base_name}.{self.output_type}")

        if not overwrite and os.path.exists(output_path):
            logger.info(f"Output file already exists, skipping: {output_path}")
            return [output_path]

        raw = input_path.read_bytes()
        content = self._extract_content(raw)

        # If outputting to p7m, this handles nested signatures (e.g., .pdf.p7m.p7m)
        # by peeling off one layer of signing.
        # For all other output types, extract recursively until we get non-PKCS#7 content.
        if self.output_type != 'p7m':
            content = self._extract_recursive(content)

        with open(output_path, 'wb') as f:
            f.write(content)

        logger.info(f"Extracted content from P7M container: {output_path}")
        return [output_path]

    @staticmethod
    def _decode_p7m(raw: bytes) -> bytes:
        """Detect PEM vs DER encoding and return DER bytes."""
        stripped = raw.strip()
        if stripped.startswith(b"-----BEGIN PKCS7-----"):
            lines = stripped.splitlines()
            b64_lines = []
            in_block = False
            for line in lines:
                line = line.strip()
                if line == b"-----BEGIN PKCS7-----":
                    in_block = True
                    continue
                if line == b"-----END PKCS7-----":
                    break
                if in_block:
                    b64_lines.append(line)
            return base64.b64decode(b"".join(b64_lines))

        # Also handle SMIME-style PEM header
        if stripped.startswith(b"-----BEGIN CMS-----"):
            lines = stripped.splitlines()
            b64_lines = []
            in_block = False
            for line in lines:
                line = line.strip()
                if line == b"-----BEGIN CMS-----":
                    in_block = True
                    continue
                if line == b"-----END CMS-----":
                    break
                if in_block:
                    b64_lines.append(line)
            return base64.b64decode(b"".join(b64_lines))

        # Try base64 decode for raw base64 without PEM headers
        try:
            decoded = base64.b64decode(stripped, validate=True)
            # Verify it looks like DER (starts with ASN.1 SEQUENCE tag 0x30)
            if decoded and decoded[0:1] == b'\x30':
                return decoded
        except (ValueError, base64.binascii.Error):
            logger.debug("Input is not valid base64, assuming DER encoding")

        # Assume DER
        return raw

    @staticmethod
    def _extract_content(raw: bytes) -> bytes:
        """Extract encapsulated content from a single PKCS#7/CMS SignedData envelope."""
        der_bytes = PKCS7Converter._decode_p7m(raw)
        content_info = cms.ContentInfo.load(der_bytes)

        content_type = content_info['content_type'].native
        if content_type != 'signed_data':
            raise ValueError(
                f"PKCS#7 content type is '{content_type}', expected 'signed_data'. "
                f"Only SignedData envelopes with attached content are supported."
            )

        signed_data = content_info['content']
        encap_info = signed_data['encap_content_info']

        if encap_info['content'] is None:
            raise ValueError(
                "No encapsulated content found (detached signature). "
                "The original file must be provided separately for detached signatures."
            )

        return encap_info['content'].native

    @staticmethod
    def _extract_recursive(content: bytes) -> bytes:
        """Recursively extract content from nested PKCS#7 envelopes."""
        while True:
            try:
                der_bytes = PKCS7Converter._decode_p7m(content)
                content_info = cms.ContentInfo.load(der_bytes)
                if content_info['content_type'].native != 'signed_data':
                    break
                signed_data = content_info['content']
                encap = signed_data['encap_content_info']
                if encap['content'] is None:
                    break
                content = encap['content'].native
            except Exception:
                break
        return content
