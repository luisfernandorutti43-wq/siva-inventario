from __future__ import annotations

import json
import math
import sys
from collections import defaultdict
from datetime import date, datetime, timedelta
from typing import Any


HISTORY_START = date(2025, 8, 1)
LOOKBACK_DAYS = 56
WEEKDAY_LOOKBACK_WEEKS = 8
FOREST_WEIGHT = 0.75
MAX_TRAINING_SAMPLES_PER_WEEKDAY = 20
FOREST_ESTIMATORS = 24
MODEL_LABEL = "Random Forest (75%) + promedio de 8 semanas (25%)"
BRAND_ALIASES = {
    "DOUBLECOIN": "DOUBLE",
    "DOUBLESTAR": "DOUBLE",
    "GOOD": "GOODYEAR",
    "GOODTYRE": "GOODYEAR",
    "GT": "GTRADIAL",
    "JK": "JKTYRES",
    "JKTYRE": "JKTYRES",
    "LING": "LINGLONG",
    "LONG": "LONGMARCH",
    "TAITON": "TAITONG",
}


def _parse_date(value: str) -> date:
    return datetime.strptime(value, "%Y-%m-%d").date()


def _identity(code: str, brand: str, measure: str) -> tuple[str, str, str]:
    normalized_code = code.strip().upper()
    if normalized_code.startswith("SIN-SKU-"):
        normalized_code = ""
    normalized_brand = "".join(character for character in brand.upper() if character.isalnum())
    normalized_brand = BRAND_ALIASES.get(normalized_brand, normalized_brand)
    return normalized_code, normalized_brand, measure.strip().upper()


def _features(series: list[float], target_date: date) -> list[float]:
    recent = series[-LOOKBACK_DAYS:]
    padded = [0.0] * (LOOKBACK_DAYS - len(recent)) + recent
    weekday = target_date.weekday()
    month_angle = 2 * math.pi * (target_date.month - 1) / 12

    return [
        padded[-1],
        padded[-2],
        padded[-7],
        padded[-14],
        padded[-21],
        padded[-28],
        padded[-35],
        padded[-42],
        padded[-49],
        padded[-56],
        sum(padded[-7:]) / 7,
        sum(padded[-28:]) / 28,
        sum(padded[-56:]) / 56,
        max(padded[-28:]),
        float(sum(value > 0 for value in padded[-28:])),
        *[float(weekday == index) for index in range(7)],
        math.sin(month_angle),
        math.cos(month_angle),
    ]


def _same_weekday_average(series: list[float], target_index: int) -> float:
    observations = []
    for week in range(1, WEEKDAY_LOOKBACK_WEEKS + 1):
        index = target_index - 7 * week
        if 0 <= index < len(series):
            observations.append(series[index])

    return sum(observations) / len(observations) if observations else 0.0


def _distribute_units(daily_units: list[int], weights: list[float]) -> list[list[int]]:
    if not weights:
        return []

    positive_weights = [max(0.0, weight) for weight in weights]
    weight_total = sum(positive_weights)
    if weight_total <= 0:
        positive_weights = [1.0] * len(weights)
        weight_total = float(len(weights))

    allocated = [[0] * len(daily_units) for _ in weights]
    for day_index, total_units in enumerate(daily_units):
        exact = [
            total_units * weight / weight_total
            for weight in positive_weights
        ]
        whole = [math.floor(value) for value in exact]
        remainder = total_units - sum(whole)
        order = sorted(
            range(len(weights)),
            key=lambda index: exact[index] - whole[index],
            reverse=True,
        )
        for index in order[:remainder]:
            whole[index] += 1
        for product_index, units in enumerate(whole):
            allocated[product_index][day_index] = units

    return allocated


def _training_target_indices(first_target_index: int, day_count: int) -> list[int]:
    indices_by_weekday: list[list[int]] = [[] for _ in range(6)]
    for target_index in range(first_target_index, day_count):
        target_date = HISTORY_START + timedelta(days=target_index)
        if target_date.weekday() < 6:
            indices_by_weekday[target_date.weekday()].append(target_index)

    sampled_indices: list[int] = []
    for weekday_indices in indices_by_weekday:
        sample_count = min(len(weekday_indices), MAX_TRAINING_SAMPLES_PER_WEEKDAY)
        if sample_count == len(weekday_indices):
            sampled_indices.extend(weekday_indices)
            continue
        last_sample_index = len(weekday_indices) - 1
        sampled_indices.extend(
            weekday_indices[
                round(index * last_sample_index / (sample_count - 1))
            ]
            for index in range(sample_count)
        )
    return sorted(sampled_indices)


def forecast(payload: dict[str, Any]) -> dict[str, Any]:
    try:
        from sklearn.ensemble import RandomForestRegressor
    except ImportError as error:
        raise ValueError(
            "Falta scikit-learn. Instala las dependencias con "
            "python -m pip install -r requirements.txt."
        ) from error

    sales = payload.get("sales")
    products = payload.get("products")
    forecast_dates = payload.get("forecast_dates")
    forecast_mode = payload.get("mode", "forecast")
    cutoff_date = _parse_date(payload["cutoff_date"])
    if (
        not isinstance(sales, list)
        or not isinstance(products, list)
        or not isinstance(forecast_dates, list)
        or not forecast_dates
    ):
        raise ValueError("El historial o el período de pronóstico está vacío.")
    if forecast_mode not in {"forecast", "backtest"}:
        raise ValueError("El tipo de pronóstico solicitado no es válido.")

    parsed_dates = [_parse_date(value) for value in forecast_dates]
    if forecast_mode == "forecast" and len(parsed_dates) != 7:
        raise ValueError("La IA requiere una semana completa de lunes a domingo.")
    if forecast_mode == "backtest" and not 1 <= len(parsed_dates) <= 7:
        raise ValueError("La evaluación histórica debe abarcar entre uno y siete días.")
    if any((right - left).days != 1 for left, right in zip(parsed_dates, parsed_dates[1:])):
        raise ValueError("Las fechas del pronóstico deben ser consecutivas.")
    if (
        forecast_mode == "forecast"
        and (parsed_dates[0].weekday() != 0 or parsed_dates[-1].weekday() != 6)
    ):
        raise ValueError("El pronóstico debe abarcar de lunes a domingo.")
    if parsed_dates[0] <= cutoff_date:
        raise ValueError("El pronóstico debe empezar después del último día con ventas.")

    day_count = (cutoff_date - HISTORY_START).days + 1
    if day_count <= LOOKBACK_DAYS:
        raise ValueError("Se necesitan al menos ocho semanas de historial diario.")

    daily_series_by_product: dict[tuple[str, str, str], list[float]] = {}
    first_sale_index_by_product: dict[tuple[str, str, str], int] = {}
    for row in sales:
        sale_date = _parse_date(str(row["fecha"]))
        if sale_date < HISTORY_START or sale_date > cutoff_date:
            continue
        identity = _identity(
            str(row.get("codigo", "")),
            str(row.get("marca", "")),
            str(row.get("medida", "")),
        )
        if not identity[1] or not identity[2]:
            continue
        series = daily_series_by_product.setdefault(identity, [0.0] * day_count)
        day_index = (sale_date - HISTORY_START).days
        series[day_index] += float(row.get("cantidad", 0))
        first_sale_index_by_product[identity] = min(
            first_sale_index_by_product.get(identity, day_index), day_index
        )

    if not daily_series_by_product:
        raise ValueError("No hay ventas válidas para entrenar el modelo de IA.")

    training_features: list[list[float]] = []
    training_targets: list[float] = []
    for identity, series in daily_series_by_product.items():
        series[:] = [max(0.0, units) for units in series]
        first_target_index = max(
            LOOKBACK_DAYS, first_sale_index_by_product[identity] + 1
        )
        for target_index in _training_target_indices(first_target_index, day_count):
            target_date = HISTORY_START + timedelta(days=target_index)
            start_index = max(0, target_index - LOOKBACK_DAYS)
            training_features.append(
                _features(series[start_index:target_index], target_date)
            )
            training_targets.append(series[target_index])

    if len(training_targets) < 10:
        raise ValueError("No hay suficientes ventas diarias para entrenar la IA.")

    model = RandomForestRegressor(
        n_estimators=FOREST_ESTIMATORS,
        max_depth=14,
        min_samples_leaf=3,
        max_features=0.8,
        random_state=42,
        n_jobs=2,
    )
    model.fit(training_features, training_targets)

    predictions_by_date: dict[tuple[str, str, str], dict[date, int]] = defaultdict(dict)
    identities = list(daily_series_by_product)
    for target_date in parsed_dates:
        if target_date.weekday() == 6:
            for identity in identities:
                daily_series_by_product[identity].append(0.0)
                predictions_by_date[identity][target_date] = 0
            continue

        model_features = [
            _features(daily_series_by_product[identity], target_date)
            for identity in identities
        ]
        daily_predictions = model.predict(model_features)
        for identity, raw_units in zip(identities, daily_predictions):
            forest_units = max(0, int(math.floor(float(raw_units) + 0.5)))
            target_index = (target_date - HISTORY_START).days
            weekday_average = _same_weekday_average(
                daily_series_by_product[identity],
                target_index,
            )
            weekday_units = max(0, int(math.floor(weekday_average + 0.5)))
            if forest_units == 0 and weekday_units > 0:
                units = weekday_units
            else:
                units = max(
                    0,
                    int(math.floor(
                    FOREST_WEIGHT * forest_units
                    + (1 - FOREST_WEIGHT) * weekday_units
                    + 0.5
                    )),
                )
            predictions_by_date[identity][target_date] = units
            daily_series_by_product[identity].append(float(units))

    forecasts: dict[str, list[int]] = {}
    matched_groups: dict[tuple[str, str, str], list[dict[str, Any]]] = defaultdict(list)
    fallback_groups: dict[tuple[str, str], list[dict[str, Any]]] = defaultdict(list)
    for product in products:
        identity = _identity(
            str(product.get("codigo", "")),
            str(product.get("marca", "")),
            str(product.get("medida", "")),
        )
        if identity in predictions_by_date:
            matched_groups[identity].append(product)
        else:
            fallback_groups[(identity[1], identity[2])].append(product)

    for identity, group_products in matched_groups.items():
        group_units = [
            predictions_by_date[identity].get(target_date, 0)
            for target_date in parsed_dates
        ]
        weights = [
            max(0.0, float(product.get("recent_units", 0)))
            for product in group_products
        ]
        allocated = _distribute_units(group_units, weights)
        for product_index, product in enumerate(group_products):
            forecasts[str(product["product_id"])] = allocated[product_index]

    for group, group_products in fallback_groups.items():
        related_identities = [
            identity
            for identity in predictions_by_date
            if identity[1:] == group
        ]
        report_identities = [
            identity for identity in related_identities if identity[0] == ""
        ]
        if report_identities:
            related_identities = report_identities
        group_units = [
            sum(
                predictions_by_date[identity].get(target_date, 0)
                for identity in related_identities
            )
            for target_date in parsed_dates
        ]
        weights = [
            max(0.0, float(product.get("recent_units", 0)))
            for product in group_products
        ]
        allocated = _distribute_units(group_units, weights)
        for product_index, product in enumerate(group_products):
            forecasts[str(product["product_id"])] = allocated[product_index]

    return {
        "model": MODEL_LABEL,
        "forecast_by_product": forecasts,
        "training_samples": len(training_targets),
        "history_days": day_count,
        "trained_through": cutoff_date.isoformat(),
        "forecast_days": len(parsed_dates),
    }


def main() -> int:
    try:
        payload = json.load(sys.stdin)
        result = forecast(payload)
        json.dump(result, sys.stdout, ensure_ascii=False, separators=(",", ":"))
        return 0
    except (KeyError, TypeError, ValueError) as error:
        print(str(error), file=sys.stderr)
        return 2


if __name__ == "__main__":
    raise SystemExit(main())
