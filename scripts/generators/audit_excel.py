"""
Generator Excel untuk Laporan Audit Dapur Katering.
Menggunakan manipulasi selektif OOXML untuk menjaga Chart1, macro, dan media EMF tetap utuh,
serta menyematkan lembar 'ESign' sebagai lembar persetujuan resmi.
"""

from __future__ import annotations
import io
import re
import zipfile
from datetime import date, datetime, timezone
from pathlib import Path
from typing import Dict, Any, List, Optional, Set

from esign_excel_service import attach_esign_to_workbook_bytes


def _set_cell_in_sheet_xml(sheet_xml: str, cell_ref: str, value: Any, is_number: bool = False) -> str:
    # Match both self-closing <c .../> and paired <c ...>...</c>
    pattern = r'<c\s+r="' + cell_ref + r'"(?:\s+s="(\d+)")?(?:\s+t="[^"]*")?(?:/>|>.*?</c>)'
    m = re.search(pattern, sheet_xml, flags=re.DOTALL)
    
    style_attr = f' s="{m.group(1)}"' if (m and m.group(1)) else ''
    
    if is_number:
        new_cell_xml = f'<c r="{cell_ref}"{style_attr}><v>{value}</v></c>'
    else:
        val_escaped = str(value).replace("&", "&amp;").replace("<", "&lt;").replace(">", "&gt;")
        new_cell_xml = f'<c r="{cell_ref}"{style_attr} t="inlineStr"><is><t>{val_escaped}</t></is></c>'
        
    if m:
        sheet_xml = sheet_xml[:m.start()] + new_cell_xml + sheet_xml[m.end():]
    return sheet_xml


def generate_audit_excel_bytes(
    template_path: Path,
    tanggal_str: str,
    catering_nama: str,
    tercentang_ids: Set[int],
    requester_name: str = "Auditor Catering",
    approval_request: Optional[Any] = None,
    approvers_status: Optional[List[Dict[str, Any]]] = None,
) -> bytes:
    with open(template_path, "rb") as f:
        template_bytes = f.read()

    with zipfile.ZipFile(io.BytesIO(template_bytes), "r") as zin:
        files = {name: zin.read(name) for name in zin.namelist()}
        
    sh1_xml = files["xl/worksheets/sheet1.xml"].decode("utf-8")
    sh1_xml = _set_cell_in_sheet_xml(sh1_xml, "E2", tanggal_str, is_number=False)
    sh1_xml = _set_cell_in_sheet_xml(sh1_xml, "E3", catering_nama, is_number=False)
    
    a_cells = [
        "E9", "E11", "E13", "E15", "E17", "E20", "E21", "E22", "E23", "E26", "E29",
        "E31", "E32", "E33", "E35", "E37", "E38", "E39", "E40", "E41", "E44", "E47",
        "E50", "E51", "E55", "E59", "E62"
    ]
    b_cells = [
        "L9", "L12", "L14", "L17", "L19", "L21", "L23", "L25", "L27", "L28", "L30",
        "L31", "L32", "L33", "L34", "L36", "L39", "L43", "L48", "L49", "L50", "L51",
        "L52", "L53", "L55", "L56", "L60"
    ]
    
    for idx, cell_ref in enumerate(a_cells, start=1):
        if idx not in tercentang_ids:
            sh1_xml = _set_cell_in_sheet_xml(sh1_xml, cell_ref, 0, is_number=True)
            
    for idx, cell_ref in enumerate(b_cells, start=28):
        if idx not in tercentang_ids:
            sh1_xml = _set_cell_in_sheet_xml(sh1_xml, cell_ref, 0, is_number=True)
            
    files["xl/worksheets/sheet1.xml"] = sh1_xml.encode("utf-8")
    
    out = io.BytesIO()
    with zipfile.ZipFile(out, "w", zipfile.ZIP_DEFLATED) as zout:
        for name, data in files.items():
            zout.writestr(name, data)
    base_bytes = out.getvalue()

    # Metadata ESign
    doc_meta = {
        "document": f"Form Audit Dapur Catering - {catering_nama}",
        "period": tanggal_str,
        "generated_date": datetime.now(timezone.utc).strftime("%Y-%m-%d %H:%M UTC"),
        "requester": requester_name,
        "approval_id": f"REQ-{approval_request.id}" if (approval_request and getattr(approval_request, "id", None)) else "-",
        "revision": f"Cycle {getattr(approval_request, 'cycle', 1)} Rev 1" if approval_request else "-",
        "status": getattr(approval_request, "status", "BASE EXPORT"),
    }

    final_bytes = attach_esign_to_workbook_bytes(
        source_bytes=base_bytes,
        metadata=doc_meta,
        approvers_status=approvers_status,
        sheet_name="ESign",
    )

    return final_bytes
