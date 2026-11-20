<?php
declare(strict_types=1);
require __DIR__ . '/app.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('index.php?page=admin');
}
requireRole('admin');
verifyCsrf();

$action = postString('action');
try {
    if ($action === 'create_course') {
        $title = postString('title');
        $description = postString('description');
        $content = postString('content');
        $instructor = postString('instructor');
        if ($title === '' || $description === '') {
            throw new RuntimeException('Course title and description are required.');
        }
        $stmt = db()->prepare('INSERT INTO courses (title,description,content,instructor) VALUES (?,?,?,?)');
        $stmt->execute([$title, $description, $content !== '' ? $content : null, $instructor]);
        flash('Course created.');
    } elseif ($action === 'update_course') {
        $id = filter_var($_POST['course_id'] ?? null, FILTER_VALIDATE_INT);
        $title = postString('title');
        $description = postString('description');
        $content = postString('content');
        if (!$id || $title === '' || $description === '') {
            throw new RuntimeException('Provide a valid course title and description.');
        }
        $stmt = db()->prepare('UPDATE courses SET title=?,description=?,content=?,instructor=? WHERE id=?');
        $stmt->execute([$title, $description, $content !== '' ? $content : null, postString('instructor'), $id]);
        flash('Course updated.');
    } elseif ($action === 'delete_course') {
        $id = filter_var($_POST['course_id'] ?? null, FILTER_VALIDATE_INT);
        if (!$id) {
            throw new RuntimeException('Choose a valid course.');
        }
        $check = db()->prepare('SELECT COUNT(*) FROM enrollments WHERE course_id=?');
        $check->execute([$id]);
        if ((int) $check->fetchColumn() > 0) {
            throw new RuntimeException('This course has enrollment or payment history and cannot be deleted.');
        }
        $stmt = db()->prepare('DELETE FROM courses WHERE id=?');
        $stmt->execute([$id]);
        flash('Course deleted.');
    } elseif ($action === 'toggle_student') {
        $id = filter_var($_POST['student_id'] ?? null, FILTER_VALIDATE_INT);
        if (!$id) {
            throw new RuntimeException('Choose a valid student account.');
        }
        $stmt = db()->prepare("UPDATE users SET is_active=IF(is_active=1,0,1) WHERE id=? AND role='student'");
        $stmt->execute([$id]);
        if ($stmt->rowCount() !== 1) {
            throw new RuntimeException('The student account could not be updated.');
        }
        flash('Student account status updated.');
    } else {
        throw new RuntimeException('Unknown administrator action.');
    }
} catch (RuntimeException $exception) {
    flash($exception->getMessage(), 'error');
} catch (Throwable $exception) {
    error_log('Admin action failed: ' . $exception->getMessage());
    flash('The requested change could not be completed.', 'error');
}
redirect('index.php?page=admin');
