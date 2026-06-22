-- Usuarios iniciales para panel admin (http://localhost:8082/admin/login)
-- Ejecutar en DBeaver contra: localhost:5434, base cotiz, usuario cotiz
--
-- Login superadmin: admin / Admin123!
-- Login ejecutivo:  ejecutivo / Ejec123!
--
-- perfil: 3 = Superadmin, 4 = Ejecutivo

INSERT INTO users (
    username,
    nombre,
    apellidop,
    apellidom,
    correo,
    perfil,
    empresa,
    ccosto,
    password,
    created_at,
    updated_at
)
VALUES
(
    'admin',
    'Admin',
    'Sistema',
    NULL,
    'admin@cotiz.local',
    3,
    NULL,
    NULL,
    '$2y$12$3YSfd6F9BuVf5EOUauPoHO3EIqG2k9xeSTRX0jSGvB9WASFkmdiWi',
    NOW(),
    NOW()
),
(
    'ejecutivo',
    'Juan',
    'Pérez',
    NULL,
    'ejecutivo@cotiz.local',
    4,
    NULL,
    NULL,
    '$2y$12$8NcLot8ECV3Dcwq.XAqRjOkZBIerATXpJloaG0XWxlNQH3oM3bvr2',
    NOW(),
    NOW()
)
ON CONFLICT (username) DO NOTHING;
