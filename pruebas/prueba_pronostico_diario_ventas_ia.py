import sys
import unittest
from datetime import date, timedelta
from pathlib import Path
from unittest.mock import patch

SERVICE_DIRECTORY = Path(__file__).resolve().parents[1] / "aplicacion" / "Servicios"
sys.path.insert(0, str(SERVICE_DIRECTORY))

from pronostico_diario_ventas_ia import forecast


class AIDailySalesForecastTest(unittest.TestCase):
    def setUp(self) -> None:
        self.week_start = date(2025, 7, 28)
        self.weeks = 32
        self.cutoff = self.week_start + timedelta(weeks=self.weeks, days=-1)
        self.forecast_start = self.cutoff + timedelta(days=1)
        self.forecast_dates = [
            (self.forecast_start + timedelta(days=offset)).isoformat()
            for offset in range(7)
        ]
        self.sales = []
        for product_index, code in enumerate(("SKU-1", "SKU-2"), start=1):
            for week_index in range(self.weeks):
                for weekday in range(6):
                    sale_date = self.week_start + timedelta(
                        weeks=week_index, days=weekday
                    )
                    if sale_date >= date(2025, 8, 1):
                        self.sales.append(
                            {
                                "codigo": code,
                                "marca": "MARCA",
                                "medida": f"225/{product_index}R17",
                                "fecha": sale_date.isoformat(),
                                "cantidad": product_index + (week_index % 3),
                            }
                        )

    def test_predicts_each_weekday_with_random_forest(self) -> None:
        result = forecast(
            {
                "cutoff_date": self.cutoff.isoformat(),
                "forecast_dates": self.forecast_dates,
                "sales": self.sales,
                "products": [
                    {
                        "product_id": "product-1",
                        "codigo": "SKU-1",
                        "marca": "MARCA",
                        "medida": "225/1R17",
                    },
                    {
                        "product_id": "product-2",
                        "codigo": "SKU-2",
                        "marca": "MARCA",
                        "medida": "225/2R17",
                    },
                ],
            }
        )

        self.assertEqual(
            result["model"],
            "Random Forest (75%) + promedio de 8 semanas (25%)",
        )
        self.assertGreater(result["training_samples"], 10)
        self.assertEqual(result["history_days"], (self.cutoff - date(2025, 8, 1)).days + 1)
        self.assertEqual(result["forecast_days"], 7)
        self.assertEqual(len(result["forecast_by_product"]["product-1"]), 7)
        self.assertEqual(date.fromisoformat(self.forecast_dates[0]).weekday(), 0)
        self.assertEqual(date.fromisoformat(self.forecast_dates[-1]).weekday(), 6)
        self.assertGreater(
            sum(result["forecast_by_product"]["product-1"]),
            0,
        )
        daily_predictions = result["forecast_by_product"]["product-1"]
        self.assertEqual(daily_predictions[-1], 0)
        self.assertEqual(sum(daily_predictions), sum(daily_predictions[:6]))

    def test_distributes_brand_measure_history_across_unmatched_codes(self) -> None:
        base_payload = {
            "cutoff_date": self.cutoff.isoformat(),
            "forecast_dates": self.forecast_dates,
            "sales": self.sales,
        }
        reference = forecast(
            {
                **base_payload,
                "products": [
                    {
                        "product_id": "sku-product",
                        "codigo": "SKU-1",
                        "marca": "MARCA",
                        "medida": "225/1R17",
                    },
                ],
            }
        )
        result = forecast(
            {
                **base_payload,
                "products": [
                    {
                        "product_id": "report-product-1",
                        "codigo": "",
                        "marca": "MARCA",
                        "medida": "225/1R17",
                        "recent_units": 2,
                    },
                    {
                        "product_id": "report-product-2",
                        "codigo": "SIN-SKU-1",
                        "marca": "MARCA",
                        "medida": "225/1R17",
                        "recent_units": 1,
                    },
                ],
            }
        )

        predictions = result["forecast_by_product"]
        report_total = [
            predictions["report-product-1"][index]
            + predictions["report-product-2"][index]
            for index in range(7)
        ]
        self.assertEqual(
            report_total,
            reference["forecast_by_product"]["sku-product"],
        )
        self.assertGreater(sum(predictions["report-product-1"]), 0)
        self.assertEqual(predictions["report-product-1"][-1], 0)
        self.assertEqual(predictions["report-product-2"][-1], 0)

    def test_distributes_shared_identity_forecast_across_models(self) -> None:
        history = [
            {**row, "codigo": "SIN-SKU-history"}
            for row in self.sales
            if row["medida"] == "225/1R17"
        ]
        products = [
            {
                "product_id": "model-1",
                "codigo": "SIN-SKU-model-1",
                "marca": "MARCA",
                "medida": "225/1R17",
                "recent_units": 3,
            },
            {
                "product_id": "model-2",
                "codigo": "SIN-SKU-model-2",
                "marca": "MARCA",
                "medida": "225/1R17",
                "recent_units": 1,
            },
        ]
        payload = {
            "cutoff_date": self.cutoff.isoformat(),
            "forecast_dates": self.forecast_dates,
            "sales": history,
        }
        reference = forecast(
            {
                **payload,
                "products": [
                    {
                        **products[0],
                        "product_id": "single-model",
                    },
                ],
            }
        )

        result = forecast({**payload, "products": products})

        for day_index, expected_units in enumerate(
            reference["forecast_by_product"]["single-model"]
        ):
            model_units = [
                result["forecast_by_product"][product["product_id"]][day_index]
                for product in products
            ]
            self.assertEqual(sum(model_units), expected_units)
            self.assertGreaterEqual(model_units[0], model_units[1])

    def test_backtests_five_days_without_using_sales_from_the_target_period(self) -> None:
        cutoff = date(2026, 11, 5)
        dates = [
            (date(2026, 11, 6) + timedelta(days=offset)).isoformat()
            for offset in range(5)
        ]
        products = [
            {
                "product_id": "product-1",
                "codigo": "SKU-1",
                "marca": "MARCA",
                "medida": "225/1R17",
            },
        ]
        payload = {
            "mode": "backtest",
            "cutoff_date": cutoff.isoformat(),
            "forecast_dates": dates,
            "sales": self.sales,
            "products": products,
        }
        reference = forecast(payload)
        with_target_sales = forecast(
            {
                **payload,
                "sales": self.sales
                + [
                    {
                        "codigo": "SKU-1",
                        "marca": "MARCA",
                        "medida": "225/1R17",
                        "fecha": "2026-11-06",
                        "cantidad": 99,
                    },
                ],
            }
        )

        self.assertEqual(reference["trained_through"], "2026-11-05")
        self.assertEqual(reference["forecast_days"], 5)
        self.assertEqual(len(reference["forecast_by_product"]["product-1"]), 5)
        self.assertEqual(reference["forecast_by_product"]["product-1"][2], 0)
        self.assertEqual(
            reference["forecast_by_product"],
            with_target_sales["forecast_by_product"],
        )

    def test_matches_known_brand_aliases_to_the_same_history(self) -> None:
        payload = {
            "cutoff_date": self.cutoff.isoformat(),
            "forecast_dates": self.forecast_dates,
            "sales": [{**row, "marca": "LING"} for row in self.sales],
            "products": [
                {
                    "product_id": "report-product",
                    "codigo": "SIN-SKU-report",
                    "marca": "LING LONG",
                    "medida": "225/1R17",
                },
            ],
        }

        result = forecast(payload)

        self.assertIn("report-product", result["forecast_by_product"])
        self.assertGreater(sum(result["forecast_by_product"]["report-product"]), 0)

    def test_uses_weekday_average_when_random_forest_predicts_zero(self) -> None:
        class ZeroPredictor:
            def __init__(self, **_: object) -> None:
                pass

            def fit(self, features: list[list[float]], targets: list[float]) -> None:
                return None

            def predict(self, features: list[list[float]]) -> list[int]:
                return [0] * len(features)

        with patch("sklearn.ensemble.RandomForestRegressor", ZeroPredictor):
            result = forecast(
                {
                    "cutoff_date": self.cutoff.isoformat(),
                    "forecast_dates": self.forecast_dates,
                    "sales": [
                        {**row, "cantidad": 1}
                        for row in self.sales
                        if row["codigo"] == "SKU-1"
                    ],
                    "products": [
                        {
                            "product_id": "product-1",
                            "codigo": "SKU-1",
                            "marca": "MARCA",
                            "medida": "225/1R17",
                        },
                    ],
                }
            )

        predictions = result["forecast_by_product"]["product-1"]
        self.assertEqual(predictions[:6], [1] * 6)
        self.assertEqual(predictions[-1], 0)

    def test_uses_blended_prediction_as_next_days_history(self) -> None:
        observed_features: list[list[list[float]]] = []

        class ConstantPredictor:
            def __init__(self, **_: object) -> None:
                pass

            def fit(self, features: list[list[float]], targets: list[float]) -> None:
                return None

            def predict(self, features: list[list[float]]) -> list[int]:
                observed_features.append(features)
                return [10] * len(features)

        with patch("sklearn.ensemble.RandomForestRegressor", ConstantPredictor):
            result = forecast(
                {
                    "cutoff_date": self.cutoff.isoformat(),
                    "forecast_dates": self.forecast_dates,
                    "sales": self.sales,
                    "products": [
                        {
                            "product_id": "product-1",
                            "codigo": "SKU-1",
                            "marca": "MARCA",
                            "medida": "225/1R17",
                        },
                    ],
                }
            )

        first_prediction = result["forecast_by_product"]["product-1"][0]
        self.assertEqual(observed_features[1][0][0], first_prediction)

    def test_blends_seventy_five_percent_forest_with_weekday_average(self) -> None:
        class ConstantPredictor:
            def __init__(self, **_: object) -> None:
                pass

            def fit(self, features: list[list[float]], targets: list[float]) -> None:
                return None

            def predict(self, features: list[list[float]]) -> list[int]:
                return [6] * len(features)

        with patch("sklearn.ensemble.RandomForestRegressor", ConstantPredictor):
            result = forecast(
                {
                    "cutoff_date": self.cutoff.isoformat(),
                    "forecast_dates": self.forecast_dates,
                    "sales": self.sales,
                    "products": [
                        {
                            "product_id": "product-1",
                            "codigo": "SKU-1",
                            "marca": "MARCA",
                            "medida": "225/1R17",
                        },
                    ],
                }
            )

        self.assertEqual(result["forecast_by_product"]["product-1"][0], 5)

    def test_rejects_partial_week(self) -> None:
        with self.assertRaisesRegex(ValueError, "semana completa"):
            forecast(
                {
                    "cutoff_date": (self.cutoff - timedelta(days=2)).isoformat(),
                    "forecast_dates": [
                        (self.cutoff - timedelta(days=1)).isoformat(),
                        self.cutoff.isoformat(),
                    ],
                    "sales": self.sales,
                    "products": [
                        {
                            "product_id": "product-1",
                            "codigo": "SKU-1",
                            "marca": "MARCA",
                            "medida": "225/1R17",
                        },
                    ],
                }
            )


if __name__ == "__main__":
    unittest.main()
