# Analisis de pronosticos

Esta carpeta contiene herramientas independientes para analizar el historico. **La aplicacion web no ejecuta estos notebooks**: el pronostico visible en el sitio usa `../aplicacion/Servicios/Servicios.php`, consulta MySQL y entrena el modelo Random Forest definido en `../aplicacion/Servicios/pronostico_diario_ventas_ia.py`. En el uso normal, las ventas registradas en **Salidas / Ventas** alimentan el histórico y actualizan el inventario en la misma operación; el Administrador puede importar un Excel mensual desde **Pronóstico de ventas** si esas ventas no se capturaron ya en el sistema.

- En **Inventario → Pronóstico de ventas**, las pestañas **Pronóstico por marca** y **Pronóstico por llanta** muestran el mismo modelo Random Forest: se entrena con ventas históricas diarias hasta el domingo anterior y predice de forma recursiva la semana completa, de lunes a domingo. El domingo queda en 0 porque no se trabaja. Al comenzar la siguiente semana, el pronóstico se actualiza automáticamente. La vista por marca suma las predicciones por llanta. Es aprendizaje automático local y requiere instalar los paquetes de `requirements.txt`; no llama un servicio de IA externo ni ejecuta los notebooks.
- `Ventas_llantas_codigos_nuevos.xlsx`: archivo fuente para los analisis de Colab; su copia actual ya esta importada en la base local.
- `ia_llantas_colab.ipynb`: compara mediana movil de cuatro semanas, Croston SBA y TSB; agrupa por marca (predeterminado), modelo o marca-modelo. Elige un metodo por grupo usando cuatro semanas de validacion y mide el resultado en ocho semanas posteriores reservadas. Guarda metricas y pronosticos en `resultados/`.
- `pronostico_rapido_llantas.ipynb`: compara metodos historicos, valida WAPE y guarda su reporte en `resultados/`. No entrena una red neuronal.
- `archivado/`: script mensual antiguo y archivos que genero. No es parte del flujo web.

Los cuadernos históricos siguen disponibles para experimentación y no modifican MySQL. Cambiar el Excel dentro de `analisis/` o ejecutar un notebook no cambia las ventas que aprende el modelo web. Para usar la IA web, instala Python y `requirements.txt`; después registra las ventas en **Salidas / Ventas**. Si tienes un Excel mensual que todavía no registraste operativamente, el Administrador puede importarlo desde **Inventario → Pronóstico de ventas**. No importes la misma venta por ambos medios.

### Resultado de la ejecucion actualizada

Con el archivo fuente disponible hasta 2026-09-30, las metricas globales de cuatro semanas de validacion y ocho semanas de holdout son:

| Metodo | WAPE validacion (4 semanas) | WAPE holdout (8 semanas) |
|---|---:|---:|
| Mediana movil de 4 semanas | 42.31 % | 33.11 % |
| TSB | 44.86 % | 37.07 % |
| Croston SBA | 46.42 % | 37.23 % |

La seleccion por grupo uso mediana para 105 grupos, Croston SBA para 18 y TSB para 9. En el holdout, esa seleccion combinada obtuvo 33.98 % WAPE, ligeramente por encima de la mediana global sola (33.11 %); por tanto, la seleccion por grupo no mejoro el error total en este periodo. El cuaderno conserva la eleccion por grupo para no usar el holdout en la seleccion. El reporte completo se guarda como `pronostico_semanal_historico_comparacion_metodos_demanda_*.xlsx` y `.csv` en `resultados/`. Estos resultados son una prueba histórica, no una garantía de resultados futuros ni una medida del pronóstico web actual. Los reportes comparativos previos se conservan como resultados antiguos.