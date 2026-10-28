#!/usr/bin/env python3
"""
backend-laravel/scripts/generate_official_excel.py
Authoritative Excel Workbook Generator for CARMEN Laravel Parity Remediation (Stage 8.1)

Directly invokes the official template preservation engines and openpyxl builders from backend/,
guaranteeing 100% parity in styles, formulas, drawing ML, and ESign sheets.
"""

from __future__ import annotations

import os
import sys
import json
import asyncio
from datetime import date, datetime, timezone
from pathlib import Path
from types import SimpleNamespace
from io import BytesIO
from typing import Any, Dict, List, Optional

# Resolve paths
CURRENT_DIR = Path(__file__).resolve().parent
GENERATORS_DIR = CURRENT_DIR / "generators"
LARAVEL_DIR = CURRENT_DIR.parent
DATA_DIR = LARAVEL_DIR / "data"

# Prioritize local generators directory first for complete independence
if str(GENERATORS_DIR) not in sys.path:
    sys.path.insert(0, str(GENERATORS_DIR))

if str(CURRENT_DIR) not in sys.path:
    sys.path.insert(0, str(CURRENT_DIR))


def _to_namespace(data: Any) -> Any:
    """Recursively converts dicts into SimpleNamespace for dot-attribute access."""
    if isinstance(data, dict):
        ns_dict = {}
        for k, v in data.items():
            ns_dict[k] = _to_namespace(v)
        return SimpleNamespace(**ns_dict)
    elif isinstance(data, list):
        return [_to_namespace(x) for x in data]
    return data


def generate_kebersihan(data: Dict[str, Any]) -> bytes:
    from kebersihan_excel import generate_kebersihan_excel_bytes

    items = []
    for it in data.get("items", []):
        ns = SimpleNamespace(**it)
        if isinstance(ns.tanggal, str):
            ns.tanggal = date.fromisoformat(ns.tanggal)
        items.append(ns)

    caterings_map = {int(k): v for k, v in data.get("caterings_map", {}).items()}
    start_date = date.fromisoformat(data["start_date"]) if data.get("start_date") else None
    end_date = date.fromisoformat(data["end_date"]) if data.get("end_date") else None
    requester = data.get("requester_name", "Admin Catering")
    approvers = data.get("approvers_status", None)

    return generate_kebersihan_excel_bytes(
        items=items,
        caterings_map=caterings_map,
        start_date=start_date,
        end_date=end_date,
        requester_name=requester,
        approvers_status=approvers,
    )


def generate_minus(data: Dict[str, Any]) -> bytes:
    from generators.minus_excel import generate_minus_excel_bytes

    entries = []
    for it in data.get("entries", []):
        ns = SimpleNamespace(**it)
        if isinstance(ns.tanggal, str):
            ns.tanggal = date.fromisoformat(ns.tanggal)
        if getattr(ns, "hasil_kalori", None) is not None:
            ns.hasil_kalori = float(ns.hasil_kalori)
        entries.append(ns)

    caterings_map = {int(k): v for k, v in data.get("caterings_map", {}).items()}
    template_path = data.get("template_path")

    return generate_minus_excel_bytes(
        entries=entries,
        caterings_map=caterings_map,
        template_path=template_path,
    )


def generate_audit(data: Dict[str, Any]) -> bytes:
    from audit_excel import generate_audit_excel_bytes

    template_path = Path(data.get("template_path") or str(DATA_DIR / "Form Audit Dapur Catering Rev 5 2023.xlsx"))
    tanggal_str = data.get("tanggal_str", date.today().strftime("%d/%m/%Y"))
    catering_nama = data.get("catering_nama", "Catering")
    tercentang_ids = set(data.get("tercentang_ids", []))
    requester_name = data.get("requester_name", "Auditor Catering")
    approvers_status = data.get("approvers_status", None)

    return generate_audit_excel_bytes(
        template_path=template_path,
        tanggal_str=tanggal_str,
        catering_nama=catering_nama,
        tercentang_ids=tercentang_ids,
        requester_name=requester_name,
        approvers_status=approvers_status,
    )


def generate_kalori_monthly(data: Dict[str, Any]) -> bytes:
    from types import SimpleNamespace
    from kalori_monthly_calc import pack_monthly_report_layout, NAMA_BULAN_INDONESIA, kh_dedup_sort_key
    from kalori_monthly_excel import generate_monthly_kalori_excel_bytes
    from esign_excel_service import attach_esign_to_workbook_bytes
    from calculations import AMBANG_KALORI_MENCAPAI

    month = int(data.get("month", 10))
    year = int(data.get("year", 2026))
    holiday_dates = set(data.get("holiday_dates", []))
    requester_name = data.get("requester_name", "Admin Catering")

    # Adapt caterings
    active_cats = []
    for c in data.get("caterings", []):
        cat_ns = SimpleNamespace(
            id=c.get("id"),
            nama=c.get("nama"),
            is_active=bool(c.get("is_active", True))
        )
        active_cats.append(cat_ns)

    # Compute scores from kalori_entries
    kalori_entries = data.get("kalori_entries", [])
    grouped: Dict[Tuple[int, str, str], List[Any]] = {}
    for entry in kalori_entries:
        s_norm = (entry.get("shift") or "").strip().lower()
        if s_norm not in ("pagi", "malam"):
            continue
        tgl_str = str(entry.get("tanggal"))
        cat_id = entry.get("catering_id")
        ns = SimpleNamespace(
            id=entry.get("id"),
            catering_id=cat_id,
            tanggal=tgl_str,
            shift=s_norm,
            total_kalori=float(entry.get("total_kalori", 0.0)),
            updated_at=entry.get("updated_at"),
            created_at=entry.get("created_at")
        )
        grouped.setdefault((cat_id, tgl_str, s_norm), []).append(ns)

    scores: Dict[Tuple[int, str, str], str] = {}
    for key, group in grouped.items():
        group.sort(key=kh_dedup_sort_key, reverse=True)
        chosen = group[0]
        scores[key] = "+1" if chosen.total_kalori >= AMBANG_KALORI_MENCAPAI else "-1"

    report_data = pack_monthly_report_layout(year, month, active_cats, scores, holiday_dates)
    excel_bytes = generate_monthly_kalori_excel_bytes(report_data)

    month_name = NAMA_BULAN_INDONESIA.get(month, f"BULAN_{month}")
    meta_kalori = {
        "document": f"Laporan Kalori Bulanan - {month_name} {year}",
        "period": f"{month_name} {year}",
        "generated_date": datetime.now(timezone.utc).strftime("%Y-%m-%d %H:%M UTC"),
        "requester": requester_name,
        "approval_id": "-",
        "revision": "-",
        "status": "BASE EXPORT",
    }
    return attach_esign_to_workbook_bytes(excel_bytes, meta_kalori, sheet_name="ESign")


def generate_punishment_monthly(data: Dict[str, Any]) -> bytes:
    from punishment_monthly_excel_ooxml import generate_punishment_monthly_excel_ooxml

    snapshot = data.get("snapshot", {})
    esign_config = data.get("config", {
        "approved_by": "",
        "checked_2_by": "",
        "checked_1_by": "",
        "prepared_by": "",
    })
    template_path = data.get("template_path") or str(DATA_DIR / "KETERANGAN PUNISHMENTT AGUSTUS 2026.xlsx")

    return generate_punishment_monthly_excel_ooxml(
        report_snapshot=snapshot,
        config=esign_config,
        template_path=template_path,
    )


def generate_performance(data: Dict[str, Any]) -> bytes:
    from performance_excel import generate_performance_excel

    snapshot = data.get("snapshot", {})
    is_draft = bool(data.get("is_draft", True))

    stream = generate_performance_excel(snapshot, is_draft=is_draft)
    return stream.getvalue()


def generate_reward_punishment(data: Dict[str, Any]) -> bytes:
    from reward_punishment_excel import generate_reward_punishment_excel

    period_data_a = data.get("period_data_a")
    period_data_b = data.get("period_data_b")
    group_code = data.get("group_code")
    template_path = data.get("template_path") or str(DATA_DIR / "REWARD & PUNISHMENT AGUSTUS 2026 NEW(1).xlsx")
    approval_info = data.get("approval_info")
    
    doc_date = None
    if data.get("doc_date"):
        try:
            doc_date = date.fromisoformat(data["doc_date"][:10])
        except Exception:
            pass

    return generate_reward_punishment_excel(
        period_data_a=period_data_a,
        period_data_b=period_data_b,
        group_code=group_code,
        template_path=template_path,
        approval_info=approval_info,
        doc_date=doc_date,
    )


def generate_menu_plan(data: Dict[str, Any]) -> bytes:
    from menu_excel_service import generate_monthly_menu_excel_bytes

    plan_dict = data.get("plan", {})
    if "effective_year" not in plan_dict:
        plan_dict["effective_year"] = plan_dict.get("year", plan_dict.get("tahun", 2026))
    if "effective_month" not in plan_dict:
        plan_dict["effective_month"] = plan_dict.get("month", plan_dict.get("bulan", 1))
    if not plan_dict.get("effective_menu_period"):
        plan_dict["effective_menu_period"] = f"{plan_dict.get('effective_year')}-{int(plan_dict.get('effective_month', 1)):02d}"
    
    plan_ns = SimpleNamespace(**plan_dict)
    if not hasattr(plan_ns, "version"):
        plan_ns.version = getattr(plan_ns, "menu_plan_version", 1)
    if not hasattr(plan_ns, "status"):
        plan_ns.status = "DRAFT"
    cat_ns = SimpleNamespace(**data.get("catering", {}))
    if not hasattr(cat_ns, "nama"):
        cat_ns.nama = data.get("catering_nama") or data.get("caterings_map", {}).get(str(getattr(plan_ns, "catering_id", ""))) or "CATERING"
    
    items = []
    for it in data.get("items", []):
        it_ns = SimpleNamespace(**it)
        if isinstance(getattr(it_ns, "menu_date", None), str):
            it_ns.menu_date = date.fromisoformat(it_ns.menu_date)
        items.append(it_ns)

    approvers_status = data.get("approvers_status", [])

    return generate_monthly_menu_excel_bytes(
        plan=plan_ns,
        catering=cat_ns,
        items=items,
        approvers_status=approvers_status,
    )


def generate_keterlambatan(data: Dict[str, Any]) -> bytes:
    from keterlambatan_excel import generate_keterlambatan_excel_bytes

    items = []
    for it in data.get("items", []):
        ns = SimpleNamespace(**it)
        if isinstance(ns.tanggal, str):
            ns.tanggal = date.fromisoformat(ns.tanggal)
        items.append(ns)

    caterings_map = {int(k): v for k, v in data.get("caterings_map", {}).items()}
    start_date = date.fromisoformat(data["start_date"]) if data.get("start_date") else None
    end_date = date.fromisoformat(data["end_date"]) if data.get("end_date") else None
    requester = data.get("requester_name", "Admin Catering")
    approvers = data.get("approvers_status", None)

    return generate_keterlambatan_excel_bytes(
        items=items,
        caterings_map=caterings_map,
        start_date=start_date,
        end_date=end_date,
        requester_name=requester,
        approvers_status=approvers,
    )


def generate_kelayakan(data: Dict[str, Any]) -> bytes:
    from kelayakan_excel import generate_kelayakan_excel_bytes

    items = []
    for it in data.get("items", []):
        ns = SimpleNamespace(**it)
        if isinstance(ns.tanggal, str):
            ns.tanggal = date.fromisoformat(ns.tanggal)
        items.append(ns)

    caterings_map = {int(k): v for k, v in data.get("caterings_map", {}).items()}
    start_date = date.fromisoformat(data["start_date"]) if data.get("start_date") else None
    end_date = date.fromisoformat(data["end_date"]) if data.get("end_date") else None
    requester = data.get("requester_name", "Admin Catering")
    approvers = data.get("approvers_status", None)

    return generate_kelayakan_excel_bytes(
        items=items,
        caterings_map=caterings_map,
        start_date=start_date,
        end_date=end_date,
        requester_name=requester,
        approvers_status=approvers,
    )


def generate_komplain_weekly(data: Dict[str, Any]) -> bytes:
    from komplain_excel import build_komplain_weekly_excel_workbook
    from esign_excel_service import attach_esign_to_workbook_bytes

    items = []
    for it in data.get("items", []):
        ns = SimpleNamespace(**it)
        if isinstance(ns.tanggal, str):
            ns.tanggal = date.fromisoformat(ns.tanggal)
        items.append(ns)

    caterings_map = {int(k): v for k, v in data.get("caterings_map", {}).items()}
    local_komplain = DATA_DIR / "komplain.xlsx"
    template_path = data.get("template_path") or str(local_komplain)
    local_uploads = LARAVEL_DIR / "storage" / "app" / "uploads"
    uploads_dir = Path(data.get("uploads_dir") or str(local_uploads))

    wb = build_komplain_weekly_excel_workbook(
        items=items,
        caterings_map=caterings_map,
        template_path=template_path,
        uploads_dir=uploads_dir,
    )

    buf = BytesIO()
    try:
        wb.save(buf)
    finally:
        wb.close()
    buf.seek(0)

    start_date = data.get("start_date", "")
    end_date = data.get("end_date", "")
    catering_nama_for_fn = data.get("catering_nama", "Semua Katering")
    requester_name = data.get("requester_name", "Admin Catering")

    meta_komplain = {
        "document": f"Laporan Komplain Katering - {catering_nama_for_fn}",
        "period": f"{start_date} s/d {end_date}",
        "generated_date": datetime.now(timezone.utc).strftime("%Y-%m-%d %H:%M UTC"),
        "requester": requester_name,
        "approval_id": "-",
        "revision": "-",
        "status": "BASE EXPORT",
    }
    return attach_esign_to_workbook_bytes(buf.getvalue(), meta_komplain, sheet_name="ESign")


def generate_komplain_single(data: Dict[str, Any]) -> bytes:
    from komplain_excel import build_komplain_single_excel_workbook
    from esign_excel_service import attach_esign_to_workbook_bytes

    it_dict = data.get("item", {})
    ns = SimpleNamespace(**it_dict)
    if isinstance(getattr(ns, "tanggal", None), str):
        ns.tanggal = date.fromisoformat(ns.tanggal)
    if not hasattr(ns, "keterangan"):
        ns.keterangan = getattr(ns, "problem", getattr(ns, "deskripsi", "-"))
    if not hasattr(ns, "menu_snapshot"):
        ns.menu_snapshot = getattr(ns, "menu", "-")

    catering_nama = data.get("catering_nama", "Catering")
    local_komplain = DATA_DIR / "komplain.xlsx"
    template_path = data.get("template_path") or str(local_komplain)
    local_uploads = LARAVEL_DIR / "storage" / "app" / "uploads"
    uploads_dir = Path(data.get("uploads_dir") or str(local_uploads))

    wb = build_komplain_single_excel_workbook(
        item=ns,
        catering_nama=catering_nama,
        template_path=template_path,
        uploads_dir=uploads_dir,
    )

    buf = BytesIO()
    try:
        wb.save(buf)
    finally:
        wb.close()
    buf.seek(0)

    t_str = ns.tanggal.strftime("%d/%m/%Y") if hasattr(ns.tanggal, "strftime") else str(ns.tanggal)
    shift_str = getattr(ns, "shift", "Pagi") or "Pagi"
    requester_name = data.get("requester_name", "Admin Catering")

    meta_single = {
        "document": f"Laporan Komplain Katering - {catering_nama}",
        "period": f"{t_str} ({shift_str})",
        "generated_date": datetime.now(timezone.utc).strftime("%Y-%m-%d %H:%M UTC"),
        "requester": requester_name,
        "approval_id": "-",
        "revision": "-",
        "status": "BASE EXPORT",
    }
    return attach_esign_to_workbook_bytes(buf.getvalue(), meta_single, sheet_name="ESign")


MODULE_HANDLERS = {
    "kebersihan": generate_kebersihan,
    "minus": generate_minus,
    "audit": generate_audit,
    "kalori_monthly": generate_kalori_monthly,
    "punishment_monthly": generate_punishment_monthly,
    "performance": generate_performance,
    "reward_punishment": generate_reward_punishment,
    "menu_plan": generate_menu_plan,
    "keterlambatan": generate_keterlambatan,
    "kelayakan": generate_kelayakan,
    "komplain_weekly": generate_komplain_weekly,
    "komplain_single": generate_komplain_single,
}


def main():
    if len(sys.argv) < 4:
        print(f"Usage: {sys.argv[0]} <module> <input_json_path> <output_xlsx_path>", file=sys.stderr)
        sys.exit(1)

    module = sys.argv[1].lower()
    input_json_path = Path(sys.argv[2])
    output_xlsx_path = Path(sys.argv[3])

    if module not in MODULE_HANDLERS:
        print(f"Error: Unknown module '{module}'. Supported modules: {list(MODULE_HANDLERS.keys())}", file=sys.stderr)
        sys.exit(2)

    if not input_json_path.exists():
        print(f"Error: Input JSON file '{input_json_path}' does not exist", file=sys.stderr)
        sys.exit(3)

    try:
        with open(input_json_path, "r", encoding="utf-8") as f:
            data = json.load(f)
    except Exception as e:
        print(f"Error reading JSON: {e}", file=sys.stderr)
        sys.exit(4)

    handler = MODULE_HANDLERS[module]
    try:
        excel_bytes = handler(data)
    except Exception as e:
        import traceback
        traceback.print_exc(file=sys.stderr)
        print(f"Error generating workbook for {module}: {e}", file=sys.stderr)
        sys.exit(5)

    if not excel_bytes or len(excel_bytes) == 0:
        print(f"Error: Generated workbook for {module} is empty", file=sys.stderr)
        sys.exit(6)

    output_xlsx_path.parent.mkdir(parents=True, exist_ok=True)
    with open(output_xlsx_path, "wb") as f:
        f.write(excel_bytes)

    print(f"SUCCESS:{len(excel_bytes)}")
    sys.exit(0)


if __name__ == "__main__":
    main()
