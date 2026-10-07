<?php

class UserManagementHelper {
    private $db;

    public function __construct($db) {
        $this->db = $db;
    }

    public function getUsers($page = 1, $limit = 10, $search = '', $filters = []) {
        $offset = ($page - 1) * $limit;
        $where = ["deleted_at IS NULL"];
        $params = [];
        $types = "";

        if (!empty($search)) {
            $where[] = "(username LIKE ? OR full_name LIKE ? OR email LIKE ?)";
            $searchTerm = "%$search%";
            $params[] = $searchTerm;
            $params[] = $searchTerm;
            $params[] = $searchTerm;
            $types .= "sss";
        }

        if (!empty($filters['role'])) {
            $where[] = "role = ?";
            $params[] = $filters['role'];
            $types .= "s";
        }

        if (!empty($filters['company_name'])) {
            $where[] = "company_name = ?";
            $params[] = $filters['company_name'];
            $types .= "s";
        }

        if (!empty($filters['department'])) {
            $where[] = "department = ?";
            $params[] = $filters['department'];
            $types .= "s";
        }

        if (isset($filters['is_active']) && $filters['is_active'] !== '') {
            $where[] = "is_active = ?";
            $params[] = (int)$filters['is_active'];
            $types .= "i";
        }

        $whereClause = implode(" AND ", $where);
        
        // Count total
        $countQuery = "SELECT COUNT(*) as total FROM users WHERE $whereClause";
        $stmtCount = $this->db->prepare($countQuery);
        if (!empty($params)) {
            $this->bindStatementParams($stmtCount, $types, $params);
        }
        $stmtCount->execute();
        $totalResult = $stmtCount->get_result()->fetch_assoc();
        $totalRecords = $totalResult['total'] ?? 0;
        $stmtCount->close();

        // Get data
        $query = "SELECT id, username, full_name, email, phone, company_name, department, role, is_active, created_at, updated_at 
                  FROM users 
                  WHERE $whereClause 
                  ORDER BY created_at DESC 
                  LIMIT ? OFFSET ?";
        
        $params[] = $limit;
        $params[] = $offset;
        $types .= "ii";

        $stmt = $this->db->prepare($query);
        $this->bindStatementParams($stmt, $types, $params);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $users = [];
        while ($row = $result->fetch_assoc()) {
            $users[] = $row;
        }
        $stmt->close();

        return [
            'data' => $users,
            'total' => $totalRecords,
            'pages' => ceil($totalRecords / $limit)
        ];
    }

    public function createUser($data) {
        // Unique Check
        if ($this->checkExists('username', $data['username'])) {
            return ['status' => 'error', 'message' => 'Username already exists.'];
        }
        if (!empty($data['email']) && $this->checkExists('email', $data['email'])) {
            return ['status' => 'error', 'message' => 'Email already exists.'];
        }

        $hash = password_hash($data['password'], PASSWORD_DEFAULT);
        $query = "INSERT INTO users (username, full_name, email, phone, password, role, company_name, department, is_active, created_at, updated_at) 
                  VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())";
        
        $stmt = $this->db->prepare($query);
        $isActive = (int)($data['is_active'] ?? 1);
        $stmt->bind_param("ssssssssi", 
            $data['username'], $data['full_name'], $data['email'], $data['phone'], $hash, 
            $data['role'], $data['company_name'], $data['department'], $isActive
        );
        
        if ($stmt->execute()) {
            return ['status' => 'success', 'message' => 'User created successfully.'];
        }
        return ['status' => 'error', 'message' => 'Failed to create user.'];
    }

    public function updateUser($id, $data, $currentUserId) {
        $user = $this->getUserById($id);
        if (!$user) return ['status' => 'error', 'message' => 'User not found.'];

        // Protection: Superadmin cannot change their own role to lower or deactivate themselves
        if ($id == $currentUserId) {
            if (isset($data['is_active']) && $data['is_active'] == 0) {
                return ['status' => 'error', 'message' => 'You cannot deactivate your own account.'];
            }
            if (isset($data['role']) && $data['role'] !== 'superadmin' && $user['role'] === 'superadmin') {
                return ['status' => 'error', 'message' => 'You cannot change your own superadmin role.'];
            }
        }

        // Unique Check
        if ($this->checkExists('username', $data['username'], $id)) {
            return ['status' => 'error', 'message' => 'Username already exists.'];
        }
        if (!empty($data['email']) && $this->checkExists('email', $data['email'], $id)) {
            return ['status' => 'error', 'message' => 'Email already exists.'];
        }

        $query = "UPDATE users SET username=?, full_name=?, email=?, phone=?, role=?, company_name=?, department=?, is_active=?, updated_at=NOW() WHERE id=?";
        $stmt = $this->db->prepare($query);
        $isActive = (int)$data['is_active'];
        $stmt->bind_param("sssssssii", 
            $data['username'], $data['full_name'], $data['email'], $data['phone'],
            $data['role'], $data['company_name'], $data['department'], 
            $isActive, $id
        );
        
        if ($stmt->execute()) {
            return ['status' => 'success', 'message' => 'User updated successfully.'];
        }
        return ['status' => 'error', 'message' => 'Failed to update user.'];
    }

    public function resetPassword($id, $newPassword, $currentUserId) {
        $hash = password_hash($newPassword, PASSWORD_DEFAULT);
        $query = "UPDATE users SET password=?, updated_at=NOW() WHERE id=?";
        $stmt = $this->db->prepare($query);
        $stmt->bind_param("si", $hash, $id);
        if ($stmt->execute()) {
            // [SECURITY] Invalidate all remember-me tokens when admin resets a user's password
            $this->db->query("DELETE FROM user_tokens WHERE user_id = ?", [$id], "i");
            return ['status' => 'success', 'message' => 'Password reset successfully.'];
        }
        return ['status' => 'error', 'message' => 'Failed to reset password.'];
    }

    public function safeDeleteUser($id, $currentUserId) {
        $id = (int)$id;
        if ($id == $currentUserId) {
            return ['status' => 'error', 'message' => 'You cannot delete your own account.'];
        }

        $user = $this->getUserById($id);
        if (!$user) return ['status' => 'error', 'message' => 'User not found.'];

        // [SECURITY] Invalidate remember-me tokens immediately
        $this->db->query("DELETE FROM user_tokens WHERE user_id = ?", [$id], "i");

        // Check if user has operational history
        $hasHistory = false;
        
        // 1. Check appointments (approved_by, verified_by etc if exists)
        $res1 = $this->db->query("SELECT id FROM appointments WHERE approved_by = ? LIMIT 1", [$id], "i");
        if ($res1 && $res1->num_rows > 0) $hasHistory = true;

        // 2. Check employees (verified_by)
        $res2 = $this->db->query("SELECT id FROM employees WHERE verified_by = ? LIMIT 1", [$id], "i");
        if ($res2 && $res2->num_rows > 0) $hasHistory = true;

        if ($hasHistory) {
            // Cannot hard delete, perform safe deactivate
            $query = "UPDATE users SET is_active=0, updated_at=NOW() WHERE id=?";
            $stmt = $this->db->prepare($query);
            $stmt->bind_param("i", $id);
            if ($stmt->execute()) {
                return ['status' => 'warning', 'message' => 'User has operational history and cannot be deleted. The account has been safely deactivated instead.'];
            }
        } else {
            // Safe to hard delete
            $query = "UPDATE users SET deleted_at = CURRENT_TIMESTAMP WHERE id=?";
            $stmt = $this->db->prepare($query);
            $stmt->bind_param("i", $id);
            if ($stmt->execute()) {
                return ['status' => 'success', 'message' => 'User completely deleted from system.'];
            }
        }
        
        return ['status' => 'error', 'message' => 'Operation failed.'];
    }

    public function getUserById($id) {
        $stmt = $this->db->prepare("SELECT id, username, full_name, email, phone, company_name, department, role, is_active FROM users WHERE id = ? AND deleted_at IS NULL");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $result = $stmt->get_result();
        return $result->fetch_assoc();
    }

    public function getAvailableRoles() {
        $res = $this->db->query("SELECT name FROM roles ORDER BY name ASC");
        $roles = [];
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $roles[] = $row['name'];
            }
        }
        return $roles;
    }

    public function getUniqueCompanies() {
        $res = $this->db->query("SELECT DISTINCT company_name FROM users WHERE company_name IS NOT NULL AND company_name != '' AND deleted_at IS NULL ORDER BY company_name ASC");
        $comps = [];
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $comps[] = $row['company_name'];
            }
        }
        return $comps;
    }

    public function getUniqueDepartments() {
        $res = $this->db->query("SELECT DISTINCT department FROM users WHERE department IS NOT NULL AND department != '' AND deleted_at IS NULL ORDER BY department ASC");
        $depts = [];
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $depts[] = $row['department'];
            }
        }
        return $depts;
    }

    private function checkExists($field, $value, $excludeId = null) {
        $query = "SELECT id FROM users WHERE $field = ? AND deleted_at IS NULL";
        if ($excludeId) {
            $query .= " AND id != " . (int)$excludeId;
        }
        $stmt = $this->db->prepare($query);
        $stmt->bind_param("s", $value);
        $stmt->execute();
        $result = $stmt->get_result();
        return $result->num_rows > 0;
    }

    private function bindStatementParams($stmt, string $types, array $params): void {
        $bind_values = [$types];
        foreach ($params as $index => $value) {
            $bind_values[] = &$params[$index];
        }
        call_user_func_array([$stmt, 'bind_param'], $bind_values);
    }
}
