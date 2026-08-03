<?php

/**
 * Handles company persistence and directory queries used by the dashboard and APIs.
 */
class CompanyModel
{
    private array $companyColumns = [];
    private array $departmentColumns = [];

    public function __construct(private PDO $pdo)
    {
        $this->companyColumns = $this->detectCompanyColumns();
        $this->departmentColumns = $this->detectDepartmentColumns();
    }

    /**
     * Detects the columns currently available in the companies table.
     * Returns an associative array of column_name => true for quick lookups.
     */

    private function detectCompanyColumns(): array
    {
        try {
            $statement = $this->pdo->query('SHOW COLUMNS FROM companies');
            $columns = $statement->fetchAll(PDO::FETCH_COLUMN);

            return array_fill_keys($columns, true);
        } catch (Throwable $e) {
            return [];
        }
    }

    /**
     * Checks whether a column exists in the current companies schema.
     * Used to adapt queries to optional columns such as `logo_path`.
     */

    private function hasCompanyColumn(string $column): bool
    {
        return isset($this->companyColumns[$column]);
    }

    /**
     * Detects the columns currently available in the departments table.
     * This mirrors `detectCompanyColumns` but for `departments`.
     */

    private function detectDepartmentColumns(): array
    {
        try {
            $statement = $this->pdo->query('SHOW COLUMNS FROM departments');
            $columns = $statement->fetchAll(PDO::FETCH_COLUMN);

            return array_fill_keys($columns, true);
        } catch (Throwable $e) {
            return [];
        }
    }

    /**
     * Checks whether a column exists in the current departments schema.
     */

    private function hasDepartmentColumn(string $column): bool
    {
        return isset($this->departmentColumns[$column]);
    }

    /**
     * all
     * Return all companies ordered by creation date.
     * Used by the admin UI and API endpoints.
     */

    public function all(): array
    {
        $statement = $this->pdo->query('SELECT * FROM companies ORDER BY created_at DESC, id DESC');

        return $statement->fetchAll();
    }

    /**
     * findById
     * Return a single company row by id, or null if not found.
     */

    public function findById(int $id): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM companies WHERE id = :id LIMIT 1');
        $statement->execute(['id' => $id]);
        $company = $statement->fetch();

        return $company ?: null;
    }

    /**
     * create
     * Insert a new company and return the new id.
     * Accepts an associative array with company fields.
     */

    public function create(array $data): int
    {
        $columns = ['name', 'type', 'address', 'city', 'zip_code', 'phone', 'email'];

        if ($this->hasCompanyColumn('logo_path')) {
            $columns[] = 'logo_path';
        }

        if ($this->hasCompanyColumn('signature_ip')) {
            $columns[] = 'signature_ip';
        }

        if ($this->hasCompanyColumn('is_active')) {
            $columns[] = 'is_active';
        }

        $placeholders = array_map(static fn (string $column): string => ':' . $column, $columns);
        $statement = $this->pdo->prepare(
            'INSERT INTO companies (' . implode(', ', $columns) . ')
             VALUES (' . implode(', ', $placeholders) . ')'
        );

        $payload = array_intersect_key($data, array_fill_keys($columns, true));
        foreach ($columns as $column) {
            $payload[$column] = $payload[$column] ?? null;
        }

        // `is_active` is NOT NULL in the schema: default new companies to active
        // when the caller does not explicitly provide a value.
        if (in_array('is_active', $columns, true) && !array_key_exists('is_active', $data)) {
            $payload['is_active'] = 1;
        }

        $statement->execute($payload);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * update
     * Update a company's fields by id.
     */

    public function update(int $id, array $data): void
    {
        $fields = ['name', 'type', 'address', 'city', 'zip_code', 'phone', 'email'];

        if ($this->hasCompanyColumn('logo_path')) {
            $fields[] = 'logo_path';
        }

        if ($this->hasCompanyColumn('signature_ip')) {
            $fields[] = 'signature_ip';
        }

        if ($this->hasCompanyColumn('is_active')) {
            $fields[] = 'is_active';
        }

        $assignments = array_map(static fn (string $column): string => $column . ' = :' . $column, $fields);
        $statement = $this->pdo->prepare(
            'UPDATE companies
             SET ' . implode(",\n                 ", $assignments) . '
             WHERE id = :id'
        );
        $payload = array_intersect_key($data, array_fill_keys($fields, true));
        foreach ($fields as $field) {
            $payload[$field] = $payload[$field] ?? null;
        }

        // `is_active` is NOT NULL in the schema: if the caller did not explicitly
        // provide it (e.g. logo-only updates), keep the company's current value
        // instead of nulling it out, which would violate the NOT NULL constraint.
        if (in_array('is_active', $fields, true) && !array_key_exists('is_active', $data)) {
            $current = $this->findById($id);
            $payload['is_active'] = $current['is_active'] ?? 1;
        }

        $payload['id'] = $id;
        $statement->execute($payload);
    }

    /**
     * delete
     * Delete a company row by id.
     */

    public function delete(int $id): void
    {
        $statement = $this->pdo->prepare('DELETE FROM companies WHERE id = :id');
        $statement->execute(['id' => $id]);
    }

    /**
     * count
     * Return the total number of companies.
     */

    public function count(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM companies')->fetchColumn();
    }

    /**
     * directoryWithAdminsAndDepartments
     * Return a company directory with aggregated admins, departments, head names,
     * departments_count and users_count per company. Designed for dashboard listing.
     */

    public function directoryWithAdminsAndDepartments(): array
    {
        $selectParts = [
            'c.id',
            'c.name',
            'c.city',
            'COUNT(DISTINCT d.id) AS departments_count',
                        '(SELECT COUNT(DISTINCT u_count.id)
                            FROM users u_count
                            INNER JOIN departments d_count ON d_count.id = u_count.department_id
                            WHERE d_count.company_id = c.id) AS users_count',
            'GROUP_CONCAT(DISTINCT CONCAT(u_admin.first_name, " ", u_admin.last_name) ORDER BY u_admin.last_name SEPARATOR "||") AS admins',
            'GROUP_CONCAT(DISTINCT d.name ORDER BY d.name SEPARATOR "||") AS departments',
                        '(SELECT COUNT(DISTINCT uc.user_id)
                            FROM user_connections uc
                            INNER JOIN users u_conn ON u_conn.id = uc.user_id
                            INNER JOIN departments d_conn ON d_conn.id = u_conn.department_id
                            WHERE d_conn.company_id = c.id
                                AND uc.logged_out_at IS NULL
                                AND uc.last_seen_at >= DATE_SUB(NOW(), INTERVAL 15 MINUTE)) AS active_connections',
                        '(SELECT GROUP_CONCAT(DISTINCT CONCAT(u_conn.first_name, " ", u_conn.last_name) ORDER BY u_conn.last_name SEPARATOR "||")
                            FROM user_connections uc
                            INNER JOIN users u_conn ON u_conn.id = uc.user_id
                            INNER JOIN departments d_conn ON d_conn.id = u_conn.department_id
                            WHERE d_conn.company_id = c.id
                                AND uc.logged_out_at IS NULL
                                AND uc.last_seen_at >= DATE_SUB(NOW(), INTERVAL 15 MINUTE)) AS connected_users',
                        '(SELECT MAX(uc.last_seen_at)
                            FROM user_connections uc
                            INNER JOIN users u_conn ON u_conn.id = uc.user_id
                            INNER JOIN departments d_conn ON d_conn.id = u_conn.department_id
                            WHERE d_conn.company_id = c.id) AS last_connection_at',
        ];

        if ($this->hasCompanyColumn('logo_path')) {
            $selectParts[] = 'c.logo_path';
        }

        if ($this->hasCompanyColumn('signature_ip')) {
            $selectParts[] = 'c.signature_ip';
        }

        if ($this->hasCompanyColumn('is_active')) {
            $selectParts[] = 'c.is_active';
        }

        $groupByParts = ['c.id', 'c.name', 'c.city'];

        if ($this->hasCompanyColumn('logo_path')) {
            $groupByParts[] = 'c.logo_path';
        }

        if ($this->hasCompanyColumn('signature_ip')) {
            $groupByParts[] = 'c.signature_ip';
        }

        if ($this->hasCompanyColumn('is_active')) {
            $groupByParts[] = 'c.is_active';
        }

        $joins = [
            'LEFT JOIN departments d ON d.company_id = c.id',
            'LEFT JOIN users u_admin ON u_admin.department_id = d.id AND u_admin.role IN ("super_admin", "admin")',
        ];

        if ($this->hasDepartmentColumn('head_user_id')) {
            $selectParts[] = 'GROUP_CONCAT(DISTINCT CONCAT(u_head.first_name, " ", u_head.last_name) ORDER BY u_head.last_name SEPARATOR "||") AS heads';
            $joins[] = 'LEFT JOIN users u_head ON u_head.id = d.head_user_id';
        } else {
            $selectParts[] = 'NULL AS heads';
        }

        $statement = $this->pdo->query(
            'SELECT ' . implode(', ', $selectParts) . '
             FROM companies c
             ' . implode("\n             ", $joins) . '
               GROUP BY ' . implode(', ', $groupByParts) . '
             ORDER BY c.name ASC'
        );

        $rows = $statement->fetchAll();

        foreach ($rows as &$row) {
            $row['logo_path'] = $row['logo_path'] ?? null;
            $row['signature_ip'] = $row['signature_ip'] ?? null;
            $row['is_active'] = array_key_exists('is_active', $row) ? (int) $row['is_active'] : 1;
            $row['admins'] = empty($row['admins']) ? [] : array_values(array_filter(explode('||', (string) $row['admins'])));
            $row['departments'] = empty($row['departments']) ? [] : array_values(array_filter(explode('||', (string) $row['departments'])));
            $row['heads'] = empty($row['heads']) ? [] : array_values(array_filter(explode('||', (string) $row['heads'])));
            $row['connected_users'] = empty($row['connected_users']) ? [] : array_values(array_filter(explode('||', (string) $row['connected_users'])));
            $row['active_connections'] = (int) ($row['active_connections'] ?? 0);
            $row['last_connection_at'] = $row['last_connection_at'] ?? null;
            $row['departments_count'] = (int) ($row['departments_count'] ?? 0);
            $row['users_count'] = (int) ($row['users_count'] ?? 0);
        }
        unset($row);

        return $rows;
    }
}
