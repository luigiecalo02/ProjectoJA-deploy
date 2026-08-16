-- Manual apply of 2026_08_09_190000_events_economia_seguros_servicios

CREATE TABLE IF NOT EXISTS tipos_seguro (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  nombre VARCHAR(255) NOT NULL,
  tipo VARCHAR(20) NOT NULL,
  descripcion TEXT NULL,
  duracion_dias INT UNSIGNED NULL,
  requiere_evento TINYINT(1) NOT NULL DEFAULT 0,
  activo TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NULL,
  updated_at TIMESTAMP NULL,
  INDEX tipos_seguro_tipo_index (tipo)
);

ALTER TABLE events
  ADD COLUMN requiere_seguro TINYINT(1) NOT NULL DEFAULT 0 AFTER metodo_pago,
  ADD COLUMN tipo_seguro_id BIGINT UNSIGNED NULL AFTER requiere_seguro,
  ADD COLUMN seguro_valor DECIMAL(12,2) NULL AFTER tipo_seguro_id,
  ADD COLUMN seguro_fecha_inicio DATE NULL AFTER seguro_valor,
  ADD COLUMN seguro_fecha_fin DATE NULL AFTER seguro_fecha_inicio,
  ADD CONSTRAINT events_tipo_seguro_id_foreign FOREIGN KEY (tipo_seguro_id) REFERENCES tipos_seguro(id) ON DELETE SET NULL;

ALTER TABLE evento_inscripcion
  ADD COLUMN total_declarado DECIMAL(12,2) NULL AFTER estado,
  ADD COLUMN revisado_por BIGINT UNSIGNED NULL AFTER inscrito_por,
  ADD COLUMN revisado_at TIMESTAMP NULL AFTER revisado_por,
  ADD COLUMN observacion_revision TEXT NULL AFTER revisado_at,
  ADD CONSTRAINT evento_inscripcion_revisado_por_foreign FOREIGN KEY (revisado_por) REFERENCES users(id) ON DELETE SET NULL;

UPDATE evento_inscripcion SET estado = 'aprobada' WHERE estado = 'confirmada';
UPDATE evento_inscripcion SET estado = 'pendiente_revision' WHERE estado = 'pendiente';

CREATE TABLE IF NOT EXISTS evento_inscripcion_persona (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  inscripcion_id BIGINT UNSIGNED NOT NULL,
  persona_id BIGINT UNSIGNED NOT NULL,
  valor_inscripcion DECIMAL(12,2) NOT NULL DEFAULT 0,
  estado VARCHAR(32) NOT NULL DEFAULT 'confirmada',
  created_at TIMESTAMP NULL,
  updated_at TIMESTAMP NULL,
  UNIQUE KEY eip_inscripcion_persona_unique (inscripcion_id, persona_id),
  INDEX evento_inscripcion_persona_estado_index (estado),
  CONSTRAINT eip_inscripcion_id_foreign FOREIGN KEY (inscripcion_id) REFERENCES evento_inscripcion(id) ON DELETE CASCADE,
  CONSTRAINT eip_persona_id_foreign FOREIGN KEY (persona_id) REFERENCES personas(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS seguros (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  persona_id BIGINT UNSIGNED NOT NULL,
  tipo_seguro_id BIGINT UNSIGNED NOT NULL,
  evento_id BIGINT UNSIGNED NULL,
  inscripcion_id BIGINT UNSIGNED NULL,
  valor DECIMAL(12,2) NOT NULL DEFAULT 0,
  fecha_inicio DATE NOT NULL,
  fecha_fin DATE NOT NULL,
  estado VARCHAR(32) NOT NULL DEFAULT 'pendiente',
  referencia_pago VARCHAR(128) NULL,
  fecha_pago TIMESTAMP NULL,
  created_at TIMESTAMP NULL,
  updated_at TIMESTAMP NULL,
  INDEX seguros_estado_index (estado),
  INDEX seguros_persona_id_estado_index (persona_id, estado),
  INDEX seguros_evento_id_persona_id_index (evento_id, persona_id),
  CONSTRAINT seguros_persona_id_foreign FOREIGN KEY (persona_id) REFERENCES personas(id) ON DELETE CASCADE,
  CONSTRAINT seguros_tipo_seguro_id_foreign FOREIGN KEY (tipo_seguro_id) REFERENCES tipos_seguro(id) ON DELETE RESTRICT,
  CONSTRAINT seguros_evento_id_foreign FOREIGN KEY (evento_id) REFERENCES events(id) ON DELETE SET NULL,
  CONSTRAINT seguros_inscripcion_id_foreign FOREIGN KEY (inscripcion_id) REFERENCES evento_inscripcion(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS evento_inscripcion_comprobante (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  inscripcion_id BIGINT UNSIGNED NOT NULL,
  file_id BIGINT UNSIGNED NOT NULL,
  valor DECIMAL(12,2) NOT NULL,
  estado VARCHAR(32) NOT NULL DEFAULT 'pendiente',
  observacion TEXT NULL,
  subido_por BIGINT UNSIGNED NULL,
  revisado_por BIGINT UNSIGNED NULL,
  revisado_at TIMESTAMP NULL,
  created_at TIMESTAMP NULL,
  updated_at TIMESTAMP NULL,
  INDEX evento_inscripcion_comprobante_estado_index (estado),
  CONSTRAINT eic_inscripcion_id_foreign FOREIGN KEY (inscripcion_id) REFERENCES evento_inscripcion(id) ON DELETE CASCADE,
  CONSTRAINT eic_file_id_foreign FOREIGN KEY (file_id) REFERENCES files(id) ON DELETE CASCADE,
  CONSTRAINT eic_subido_por_foreign FOREIGN KEY (subido_por) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT eic_revisado_por_foreign FOREIGN KEY (revisado_por) REFERENCES users(id) ON DELETE SET NULL
);

ALTER TABLE evento_pago
  ADD COLUMN pagable_type VARCHAR(255) NULL AFTER id,
  ADD COLUMN pagable_id BIGINT UNSIGNED NULL AFTER pagable_type,
  ADD COLUMN referencia VARCHAR(128) NULL AFTER pagado_at,
  ADD INDEX evento_pago_pagable_type_pagable_id_index (pagable_type, pagable_id);

ALTER TABLE evento_pago DROP FOREIGN KEY evento_pago_inscripcion_id_foreign;
ALTER TABLE evento_pago MODIFY inscripcion_id BIGINT UNSIGNED NULL;
ALTER TABLE evento_pago ADD CONSTRAINT evento_pago_inscripcion_id_foreign FOREIGN KEY (inscripcion_id) REFERENCES evento_inscripcion(id) ON DELETE SET NULL;

CREATE TABLE IF NOT EXISTS productos_servicios (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  nombre VARCHAR(255) NOT NULL,
  tipo VARCHAR(64) NOT NULL,
  descripcion TEXT NULL,
  precio DECIMAL(12,2) NOT NULL DEFAULT 0,
  unidad VARCHAR(32) NOT NULL DEFAULT 'UNIDAD',
  activo TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NULL,
  updated_at TIMESTAMP NULL,
  INDEX productos_servicios_tipo_index (tipo)
);

CREATE TABLE IF NOT EXISTS evento_producto_servicio (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  evento_id BIGINT UNSIGNED NOT NULL,
  producto_servicio_id BIGINT UNSIGNED NOT NULL,
  precio DECIMAL(12,2) NOT NULL,
  activo TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NULL,
  updated_at TIMESTAMP NULL,
  UNIQUE KEY eps_evento_producto_unique (evento_id, producto_servicio_id),
  CONSTRAINT eps_evento_id_foreign FOREIGN KEY (evento_id) REFERENCES events(id) ON DELETE CASCADE,
  CONSTRAINT eps_producto_servicio_id_foreign FOREIGN KEY (producto_servicio_id) REFERENCES productos_servicios(id) ON DELETE RESTRICT
);

CREATE TABLE IF NOT EXISTS evento_servicio_reserva (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  evento_id BIGINT UNSIGNED NOT NULL,
  evento_producto_servicio_id BIGINT UNSIGNED NOT NULL,
  persona_id BIGINT UNSIGNED NOT NULL,
  inscripcion_id BIGINT UNSIGNED NULL,
  precio_unitario DECIMAL(12,2) NOT NULL DEFAULT 0,
  cantidad INT UNSIGNED NOT NULL DEFAULT 1,
  valor_total DECIMAL(12,2) NOT NULL DEFAULT 0,
  fecha_inicio DATE NULL,
  fecha_fin DATE NULL,
  cantidad_dias INT UNSIGNED NULL,
  precio_dia DECIMAL(12,2) NULL,
  fecha DATE NULL,
  estado VARCHAR(32) NOT NULL DEFAULT 'reservada',
  created_at TIMESTAMP NULL,
  updated_at TIMESTAMP NULL,
  INDEX evento_servicio_reserva_estado_index (estado),
  INDEX evento_servicio_reserva_evento_id_persona_id_index (evento_id, persona_id),
  CONSTRAINT esr_evento_id_foreign FOREIGN KEY (evento_id) REFERENCES events(id) ON DELETE CASCADE,
  CONSTRAINT esr_evento_producto_servicio_id_foreign FOREIGN KEY (evento_producto_servicio_id) REFERENCES evento_producto_servicio(id) ON DELETE RESTRICT,
  CONSTRAINT esr_persona_id_foreign FOREIGN KEY (persona_id) REFERENCES personas(id) ON DELETE CASCADE,
  CONSTRAINT esr_inscripcion_id_foreign FOREIGN KEY (inscripcion_id) REFERENCES evento_inscripcion(id) ON DELETE SET NULL
);

INSERT INTO tipos_seguro (nombre, tipo, descripcion, duracion_dias, requiere_evento, activo, created_at, updated_at)
SELECT 'Seguro anual', 'ANUAL', 'Cobertura anual vigente para múltiples eventos', 365, 0, 1, NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM tipos_seguro WHERE tipo = 'ANUAL' LIMIT 1);

INSERT INTO tipos_seguro (nombre, tipo, descripcion, duracion_dias, requiere_evento, activo, created_at, updated_at)
SELECT 'Seguro de evento', 'EVENTO', 'Cobertura específica para un evento', NULL, 1, 1, NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM tipos_seguro WHERE tipo = 'EVENTO' LIMIT 1);

INSERT INTO productos_servicios (nombre, tipo, descripcion, precio, unidad, activo, created_at, updated_at)
SELECT 'Pasadía', 'PASADIA', 'Acceso de un día al evento', 25000, 'DIA', 1, NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM productos_servicios WHERE tipo = 'PASADIA' LIMIT 1);

INSERT INTO productos_servicios (nombre, tipo, descripcion, precio, unidad, activo, created_at, updated_at)
SELECT 'Cabaña', 'CABANA', 'Hospedaje por día', 80000, 'DIA', 1, NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM productos_servicios WHERE tipo = 'CABANA' LIMIT 1);

INSERT INTO productos_servicios (nombre, tipo, descripcion, precio, unidad, activo, created_at, updated_at)
SELECT 'Alimentación', 'ALIMENTACION', 'Servicio de alimentación', 0, 'UNIDAD', 1, NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM productos_servicios WHERE tipo = 'ALIMENTACION' LIMIT 1);

INSERT INTO productos_servicios (nombre, tipo, descripcion, precio, unidad, activo, created_at, updated_at)
SELECT 'Parqueadero', 'PARQUEADERO', 'Parqueo por día', 10000, 'DIA', 1, NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM productos_servicios WHERE tipo = 'PARQUEADERO' LIMIT 1);

INSERT INTO migrations (migration, batch)
SELECT '2026_08_09_190000_events_economia_seguros_servicios', COALESCE(MAX(batch), 0) + 1
FROM migrations
WHERE NOT EXISTS (
  SELECT 1 FROM migrations WHERE migration = '2026_08_09_190000_events_economia_seguros_servicios'
);
