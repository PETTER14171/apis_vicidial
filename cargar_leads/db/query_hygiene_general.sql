WITH ranked AS (
  SELECT l.*,
         ROW_NUMBER() OVER (
           PARTITION BY l.phone_number
           ORDER BY l.created_at_date DESC, l.id DESC
         ) AS rn
  FROM leads_08112025 AS l
  WHERE
    -- Fuera números en blacklist
    NOT EXISTS (
      SELECT 1
      FROM blacklist_numbers bl
      WHERE bl.phone_number COLLATE utf8mb4_0900_ai_ci
            = l.phone_number COLLATE utf8mb4_0900_ai_ci
    )
    -- Fuera miembros activos
    AND NOT EXISTS (
      SELECT 1
      FROM active_members am
      WHERE am.phone_number COLLATE utf8mb4_0900_ai_ci
            = l.phone_number COLLATE utf8mb4_0900_ai_ci
    )
    -- Fuera cancelaciones (si el negocio decide excluirlas del marcaje)
    AND NOT EXISTS (
      SELECT 1
      FROM cancellations c
      WHERE c.phone_number COLLATE utf8mb4_0900_ai_ci
            = l.phone_number COLLATE utf8mb4_0900_ai_ci
    )
    -- Fuera enrolados (ya clientes/alta)
    AND NOT EXISTS (
      SELECT 1
      FROM enrollment_list en
      WHERE en.phone_number COLLATE utf8mb4_0900_ai_ci
            = l.phone_number COLLATE utf8mb4_0900_ai_ci
    )
)
SELECT *
FROM ranked
WHERE rn = 1
ORDER BY
  created_at_date IS NULL ASC,
  created_at_date DESC,
  id DESC;
