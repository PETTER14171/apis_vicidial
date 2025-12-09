WITH ranked AS (
  SELECT l.*,
         ROW_NUMBER() OVER (
           PARTITION BY l.phone_number
           ORDER BY l.created_at_date DESC, l.id DESC
         ) AS rn
  FROM leads_07112025 AS l
  LEFT JOIN blacklist_numbers AS bl #cambiar la tabla por la que se va a higienizar 
    ON bl.phone_number COLLATE utf8mb4_0900_ai_ci
       = l.phone_number COLLATE utf8mb4_0900_ai_ci
  WHERE bl.phone_number IS NULL
)
SELECT *
FROM ranked
WHERE rn = 1
ORDER BY
  created_at_date IS NULL ASC,
  created_at_date DESC,
  id DESC;
