-- Precios acompañantes/directiva y descuentos por cargo en events
ALTER TABLE events
  ADD COLUMN precio_acompanante DECIMAL(12,2) NULL AFTER precio,
  ADD COLUMN precio_acompanante_menor DECIMAL(12,2) NULL AFTER precio_acompanante,
  ADD COLUMN precio_directiva DECIMAL(12,2) NULL AFTER precio_acompanante_menor,
  ADD COLUMN descuentos_directiva JSON NULL AFTER precio_directiva;

INSERT INTO migrations (migration, batch)
SELECT '2026_08_10_100000_events_precios_acompanantes_directiva', COALESCE(MAX(batch), 0) + 1
FROM migrations
WHERE NOT EXISTS (
  SELECT 1 FROM migrations WHERE migration = '2026_08_10_100000_events_precios_acompanantes_directiva'
);
