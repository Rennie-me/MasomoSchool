<?php
declare(strict_types=1);
require __DIR__ . '/app.php';

$page = $_GET['page'] ?? 'home';
$user = currentUser();
$flashMessage = takeFlash();

if ($page === 'logout' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $_SESSION = [];
    session_destroy();
    redirect('index.php');
} elseif ($page === 'logout') {
    redirect('index.php');
}

$courses = db()->query('SELECT c.*, COALESCE(ec.students_count,0) AS students_count FROM courses c LEFT JOIN (SELECT course_id,COUNT(*) AS students_count FROM enrollments WHERE status="active" GROUP BY course_id) ec ON ec.course_id=c.id ORDER BY c.created_at DESC')->fetchAll();
$studentEnrollments = [];
if ($user && $user['role'] === 'student') {
    $stmt = db()->prepare('SELECT e.id AS enrollment_id, e.status AS enrollment_status, c.id AS course_id, c.title, c.description, c.content, c.instructor, p.id AS payment_id, p.status AS payment_status, p.method, p.checkout_request_id, p.provider_reference FROM enrollments e JOIN courses c ON c.id = e.course_id LEFT JOIN payments p ON p.id=(SELECT MAX(p2.id) FROM payments p2 WHERE p2.enrollment_id=e.id) WHERE e.student_id = ? ORDER BY e.enrolled_at DESC');
    $stmt->execute([$user['id']]);
    $studentEnrollments = $stmt->fetchAll();
    $phoneStmt = db()->prepare('SELECT phone FROM users WHERE id=?');
    $phoneStmt->execute([$user['id']]);
    $studentPhone = (string) ($phoneStmt->fetchColumn() ?: '');
}

$pageTitles = ['home' => 'Learn something new', 'courses' => 'Explore courses', 'login' => 'Welcome back', 'register' => 'Create your account', 'dashboard' => 'Student dashboard', 'admin' => 'Administrator dashboard'];
$title = $pageTitles[$page] ?? 'Masomo';
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($title) ?> | Masomo School</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = { theme: { extend: { colors: { brand: { 50:'#eff6ff', 100:'#dbeafe', 600:'#2563eb', 700:'#1d4ed8', 900:'#1e3a8a' } } } } };
    </script>
</head>
<body class="min-h-screen bg-slate-50 text-slate-800">
<header class="bg-white border-b border-slate-200">
    <nav class="max-w-6xl mx-auto px-5 h-16 flex items-center justify-between">
        <a href="index.php" class="font-black tracking-tight text-xl text-brand-700">Masomo School<span class="text-slate-500">.</span></a>
        <div class="flex items-center gap-5 text-sm font-medium">
            <a class="hover:text-brand-700" href="index.php?page=courses">Courses</a>
            <?php if ($user): ?>
                <?php if ($user['role'] === 'admin'): ?><a class="hover:text-brand-700" href="index.php?page=admin">Admin</a>
                <?php else: ?><a class="hover:text-brand-700" href="index.php?page=dashboard">My learning</a><?php endif; ?>
                <span class="hidden sm:inline text-slate-500"><?= e($user['name']) ?></span>
                <form action="index.php?page=logout" method="post"><input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>"><button class="text-brand-700">Log out</button></form>
            <?php else: ?>
                <a class="hover:text-brand-700" href="index.php?page=login">Log in</a>
                <a class="rounded-lg bg-brand-600 px-4 py-2 text-white hover:bg-brand-700" href="index.php?page=register">Get started</a>
            <?php endif; ?>
        </div>
    </nav>
</header>
<main class="max-w-6xl mx-auto px-5 py-10">
    <?php if ($flashMessage): ?>
        <div class="mb-6 rounded-xl border px-4 py-3 <?= $flashMessage['type'] === 'error' ? 'border-red-200 bg-red-50 text-red-800' : 'border-blue-200 bg-blue-50 text-blue-900' ?>">
            <?= e($flashMessage['message']) ?>
        </div>
    <?php endif; ?>

    <?php if ($page === 'home' || $page === 'courses'): ?>
        <?php if ($page === 'home'): ?>
        <section class="rounded-3xl bg-gradient-to-br from-brand-900 to-brand-600 px-8 py-14 text-white md:px-14 md:py-20">
            <p class="mb-3 text-sm font-bold uppercase tracking-[.2em] text-blue-100">Your next chapter starts here</p>
            <h1 class="max-w-2xl text-4xl font-black leading-tight md:text-6xl">Build skills. Open doors.</h1>
            <p class="mt-5 max-w-xl text-blue-100">Browse practical courses, enroll securely, and learn at your own pace. Every course enrollment is just KSh 1.</p>
            <a href="index.php?page=courses" class="mt-8 inline-block rounded-xl bg-white px-6 py-3 font-bold text-brand-900 hover:bg-blue-50">Browse courses</a>
        </section>
        <div class="mt-12 flex items-end justify-between"><div><p class="text-sm font-bold uppercase tracking-widest text-brand-700">Start learning</p><h2 class="mt-2 text-3xl font-black">Featured courses</h2></div><a class="font-semibold text-brand-700" href="index.php?page=courses">View all →</a></div>
        <?php else: ?>
        <div class="mb-8"><p class="text-sm font-bold uppercase tracking-widest text-brand-700">Learning catalog</p><h1 class="mt-2 text-4xl font-black">Explore courses</h1><p class="mt-3 text-slate-600">Find your next skill and enroll for KSh 1.</p></div>
        <?php endif; ?>
        <section class="mt-6 grid gap-5 md:grid-cols-2 lg:grid-cols-3">
            <?php foreach (array_slice($courses, 0, $page === 'home' ? 3 : count($courses)) as $course): ?>
            <article class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                <div class="mb-5 flex h-11 w-11 items-center justify-center rounded-xl bg-blue-50 text-xl text-brand-700">✦</div>
                <h3 class="text-xl font-bold"><?= e($course['title']) ?></h3>
                <p class="mt-2 text-sm text-slate-600"><?= e(strlen($course['description']) > 130 ? substr($course['description'], 0, 127) . '...' : $course['description']) ?></p>
                <div class="mt-5 flex items-center justify-between text-sm text-slate-500"><span><?= e($course['instructor']) ?></span><span><?= (int)$course['students_count'] ?> enrolled</span></div>
                <div class="mt-5 flex items-center justify-between border-t border-slate-100 pt-4"><span class="font-bold text-brand-700">KSh 1</span>
                    <?php if ($user && $user['role'] === 'student'): ?>
                    <form action="enroll.php" method="post"><input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>"><input type="hidden" name="course_id" value="<?= (int)$course['id'] ?>"><button class="rounded-lg bg-brand-600 px-4 py-2 font-semibold text-white hover:bg-brand-700">Enroll</button></form>
                    <?php elseif (!$user): ?><a href="index.php?page=login" class="rounded-lg bg-brand-600 px-4 py-2 font-semibold text-white hover:bg-brand-700">Enroll</a>
                    <?php else: ?><span class="text-xs text-slate-400">Student enrollment only</span><?php endif; ?>
                </div>
            </article>
            <?php endforeach; ?>
            <?php if (!$courses): ?><div class="rounded-2xl border border-dashed border-slate-300 bg-white p-8 text-slate-500">No courses are available yet. Check back soon.</div><?php endif; ?>
        </section>
    <?php elseif ($page === 'login' || $page === 'register'): ?>
        <?php if ($user): redirect($user['role'] === 'admin' ? 'index.php?page=admin' : 'index.php?page=dashboard'); endif; ?>
        <div class="mx-auto max-w-md rounded-2xl border border-slate-200 bg-white p-8 shadow-sm">
            <p class="text-sm font-bold uppercase tracking-widest text-brand-700"><?= $page === 'login' ? 'Student portal' : 'Join Masomo' ?></p>
            <h1 class="mt-2 text-3xl font-black"><?= $page === 'login' ? 'Welcome back' : 'Create your account' ?></h1>
            <form action="auth.php" method="post" class="mt-7 space-y-4">
                <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>"><input type="hidden" name="action" value="<?= e($page) ?>">
                <?php if ($page === 'register'): ?>
                <label class="block text-sm font-semibold">Full name<input required name="name" maxlength="120" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2.5 font-normal"></label>
                <label class="block text-sm font-semibold">Phone number <span class="font-normal text-slate-500">(for M-Pesa, e.g. 0712345678)</span><input name="phone" maxlength="20" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2.5 font-normal"></label>
                <?php endif; ?>
                <label class="block text-sm font-semibold">Email<input required type="email" name="email" maxlength="190" autocomplete="email" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2.5 font-normal"></label>
                <label class="block text-sm font-semibold">Password<input required type="password" name="password" minlength="<?= $page === 'register' ? 8 : 1 ?>" autocomplete="<?= $page === 'login' ? 'current-password' : 'new-password' ?>" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2.5 font-normal"></label>
                <button class="w-full rounded-lg bg-brand-600 py-3 font-bold text-white hover:bg-brand-700"><?= $page === 'login' ? 'Log in' : 'Register' ?></button>
            </form>
            <p class="mt-5 text-sm text-slate-600"><?= $page === 'login' ? 'New to Masomo?' : 'Already registered?' ?> <a class="font-semibold text-brand-700" href="index.php?page=<?= $page === 'login' ? 'register' : 'login' ?>"><?= $page === 'login' ? 'Create an account' : 'Log in' ?></a></p>
        </div>
    <?php elseif ($page === 'dashboard'): ?>
        <?php $student = requireRole('student'); ?>
        <div class="flex flex-col justify-between gap-4 sm:flex-row sm:items-end"><div><p class="text-sm font-bold uppercase tracking-widest text-brand-700">Student portal</p><h1 class="mt-2 text-4xl font-black">Welcome, <?= e($student['name']) ?></h1></div><a href="index.php?page=courses" class="rounded-lg bg-brand-600 px-5 py-3 text-center font-semibold text-white">Browse courses</a></div>
        <form action="auth.php" method="post" class="mt-6 flex flex-col gap-3 rounded-xl border border-slate-200 bg-white p-4 sm:flex-row sm:items-end"><input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>"><input type="hidden" name="action" value="update_phone"><label class="flex-1 text-sm font-semibold">M-Pesa phone number<input name="phone" maxlength="20" value="<?= e($studentPhone) ?>" placeholder="0712345678" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 font-normal"><span class="text-xs font-normal text-slate-500">Required only when choosing M-Pesa.</span></label><button class="rounded-lg border border-slate-300 px-4 py-2 font-semibold">Save phone</button></form>
        <h2 class="mb-4 mt-10 text-2xl font-bold">My courses</h2>
        <div class="grid gap-4 md:grid-cols-2">
        <?php foreach ($studentEnrollments as $enrollment): ?>
            <article class="rounded-2xl border border-slate-200 bg-white p-6">
                <div class="flex items-start justify-between gap-4"><div><h3 class="text-xl font-bold"><?= e($enrollment['title']) ?></h3><p class="mt-1 text-sm text-slate-500"><?= e($enrollment['instructor']) ?></p></div><span class="rounded-full px-3 py-1 text-xs font-bold <?= $enrollment['enrollment_status'] === 'active' ? 'bg-green-100 text-green-800' : 'bg-amber-100 text-amber-800' ?>"><?= $enrollment['enrollment_status'] === 'active' ? 'Active' : ($enrollment['payment_status'] === 'pending' ? 'Payment pending' : ($enrollment['payment_status'] === 'failed' ? 'Payment failed' : 'Not paid')) ?></span></div>
                <p class="mt-4 text-sm text-slate-600"><?= e($enrollment['description']) ?></p>
                <?php if ($enrollment['enrollment_status'] !== 'active'): ?>
                <?php if ($enrollment['payment_status'] === 'pending'): ?>
                <p class="mt-4 text-xs text-slate-500">M-Pesa payment is pending confirmation.</p>
                <?php else: ?>
                <p class="mt-4 text-xs text-slate-500">Complete your enrollment with M-Pesa.</p>
                <form action="enroll.php" method="post" class="mt-3 flex flex-wrap gap-2"><input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>"><input type="hidden" name="course_id" value="<?= (int)$enrollment['course_id'] ?>"><input type="hidden" name="method" value="mpesa"><button type="submit" class="rounded-lg bg-green-600 px-4 py-2 text-sm font-semibold text-white">Pay KSh 1 with M-Pesa</button></form>
                <?php endif; ?>
                <?php if (sandboxPaymentSimulationEnabled() && $enrollment['payment_status'] === 'pending' && $enrollment['method'] === 'mpesa' && !empty($enrollment['checkout_request_id'])): ?>
                <form action="sandbox_payment.php" method="post" class="mt-3"><input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>"><input type="hidden" name="payment_id" value="<?= (int)$enrollment['payment_id'] ?>"><button type="submit" class="rounded-lg border border-amber-600 px-4 py-2 text-sm font-semibold text-amber-800">Simulate sandbox success (no charge)</button></form>
                <?php endif; ?>
                <?php else: ?><div class="mt-4 rounded-lg bg-blue-50 p-4"><?php if (str_starts_with((string) $enrollment['provider_reference'], 'SANDBOX-TEST-')): ?><p class="text-xs font-semibold text-amber-800">Sandbox test only — no payment was charged.</p><?php endif; ?><p class="text-sm font-bold text-brand-700">Course materials</p><p class="mt-2 whitespace-pre-line text-sm text-slate-700"><?= e($enrollment['content'] ?: 'Your instructor will add course materials soon.') ?></p></div><?php endif; ?>
            </article>
        <?php endforeach; ?>
        <?php if (!$studentEnrollments): ?><div class="rounded-xl border border-dashed border-slate-300 bg-white p-8 text-slate-500">No enrollments yet. Browse the catalog to get started.</div><?php endif; ?>
        </div>
    <?php elseif ($page === 'admin'): ?>
        <?php requireRole('admin'); $students = db()->query("SELECT id,name,email,phone,is_active,created_at FROM users WHERE role='student' ORDER BY created_at DESC")->fetchAll(); $enrollments = db()->query('SELECT e.status,u.name AS student,c.title AS course,e.enrolled_at FROM enrollments e JOIN users u ON u.id=e.student_id JOIN courses c ON c.id=e.course_id ORDER BY e.enrolled_at DESC')->fetchAll(); $payments = db()->query('SELECT p.*,u.name AS student,c.title AS course FROM payments p JOIN enrollments e ON e.id=p.enrollment_id JOIN users u ON u.id=e.student_id JOIN courses c ON c.id=e.course_id ORDER BY p.created_at DESC LIMIT 100')->fetchAll(); ?>
        <div><p class="text-sm font-bold uppercase tracking-widest text-brand-700">Administration</p><h1 class="mt-2 text-4xl font-black">School dashboard</h1><p class="mt-2 text-slate-600">Manage the catalog, students, enrollments, and payments.</p></div>
        <section class="mt-8 grid gap-4 sm:grid-cols-3"><div class="rounded-xl bg-white p-5 shadow-sm"><p class="text-sm text-slate-500">Students</p><p class="mt-1 text-3xl font-black"><?= count($students) ?></p></div><div class="rounded-xl bg-white p-5 shadow-sm"><p class="text-sm text-slate-500">Courses</p><p class="mt-1 text-3xl font-black"><?= count($courses) ?></p></div><div class="rounded-xl bg-white p-5 shadow-sm"><p class="text-sm text-slate-500">Enrollments</p><p class="mt-1 text-3xl font-black"><?= count($enrollments) ?></p></div></section>
        <section class="mt-8 rounded-2xl border border-slate-200 bg-white p-6">
            <h2 class="text-xl font-bold">Create a course</h2><form action="admin.php" method="post" class="mt-4 grid gap-3 md:grid-cols-2"><input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>"><input type="hidden" name="action" value="create_course"><input required name="title" maxlength="180" placeholder="Course title" class="rounded-lg border border-slate-300 px-3 py-2"><input name="instructor" maxlength="120" placeholder="Instructor" class="rounded-lg border border-slate-300 px-3 py-2"><textarea required name="description" rows="2" placeholder="Course description shown in the catalog" class="rounded-lg border border-slate-300 px-3 py-2 md:col-span-2"></textarea><textarea name="content" rows="4" placeholder="Course materials (visible only after verified enrollment)" class="rounded-lg border border-slate-300 px-3 py-2 md:col-span-2"></textarea><button class="w-fit rounded-lg bg-brand-600 px-5 py-2 font-semibold text-white">Add course</button></form>
        </section>
        <section class="mt-8"><h2 class="mb-4 text-xl font-bold">Courses</h2><div class="space-y-3">
        <?php foreach ($courses as $course): ?><form action="admin.php" method="post" class="grid gap-3 rounded-xl border border-slate-200 bg-white p-4 md:grid-cols-[1fr_1fr_2fr_2fr_auto_auto] md:items-center"><input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>"><input type="hidden" name="course_id" value="<?= (int)$course['id'] ?>"><input required name="title" value="<?= e($course['title']) ?>" maxlength="180" class="rounded-lg border border-slate-300 px-3 py-2"><input name="instructor" value="<?= e($course['instructor']) ?>" maxlength="120" class="rounded-lg border border-slate-300 px-3 py-2"><textarea required name="description" rows="3" aria-label="Course description" class="rounded-lg border border-slate-300 px-3 py-2"><?= e($course['description']) ?></textarea><textarea name="content" rows="3" aria-label="Course materials" placeholder="Course materials (shown to enrolled students)" class="rounded-lg border border-slate-300 px-3 py-2"><?= e($course['content']) ?></textarea><button name="action" value="update_course" class="rounded-lg border px-3 py-2 font-semibold">Save</button><button name="action" value="delete_course" onclick="return confirm('Delete this course?')" class="rounded-lg border border-red-200 px-3 py-2 text-red-700">Delete</button></form><?php endforeach; ?>
        </div></section>
        <section class="mt-10"><h2 class="mb-4 text-xl font-bold">Students</h2><div class="overflow-x-auto rounded-xl border border-slate-200 bg-white"><table class="w-full text-left text-sm"><thead class="bg-slate-100"><tr><th class="p-3">Name</th><th class="p-3">Email</th><th class="p-3">Phone</th><th class="p-3">Status</th><th class="p-3">Action</th></tr></thead><tbody><?php foreach ($students as $student): ?><tr class="border-t"><td class="p-3"><?= e($student['name']) ?></td><td class="p-3"><?= e($student['email']) ?></td><td class="p-3"><?= e($student['phone']) ?></td><td class="p-3"><?= $student['is_active'] ? 'Active' : 'Disabled' ?></td><td class="p-3"><form action="admin.php" method="post"><input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>"><input type="hidden" name="student_id" value="<?= (int)$student['id'] ?>"><button name="action" value="toggle_student" class="font-semibold text-brand-700"><?= $student['is_active'] ? 'Disable' : 'Enable' ?></button></form></td></tr><?php endforeach; ?></tbody></table></div></section>
        <section class="mt-10"><h2 class="mb-4 text-xl font-bold">Enrollments</h2><div class="overflow-x-auto rounded-xl border border-slate-200 bg-white"><table class="w-full text-left text-sm"><thead class="bg-slate-100"><tr><th class="p-3">Student</th><th class="p-3">Course</th><th class="p-3">Status</th><th class="p-3">Date</th></tr></thead><tbody><?php foreach ($enrollments as $item): ?><tr class="border-t"><td class="p-3"><?= e($item['student']) ?></td><td class="p-3"><?= e($item['course']) ?></td><td class="p-3"><?= e($item['status']) ?></td><td class="p-3"><?= e($item['enrolled_at']) ?></td></tr><?php endforeach; ?></tbody></table></div></section>
        <section class="mt-10"><h2 class="mb-4 text-xl font-bold">Payment transactions</h2><div class="overflow-x-auto rounded-xl border border-slate-200 bg-white"><table class="w-full text-left text-sm"><thead class="bg-slate-100"><tr><th class="p-3">Student / course</th><th class="p-3">Reference</th><th class="p-3">Method</th><th class="p-3">Amount</th><th class="p-3">Status</th><th class="p-3">Date</th></tr></thead><tbody><?php foreach ($payments as $payment): ?><tr class="border-t"><td class="p-3"><?= e($payment['student']) ?> / <?= e($payment['course']) ?></td><td class="p-3 font-mono"><?= e($payment['reference']) ?></td><td class="p-3"><?= e(strtoupper($payment['method'])) ?></td><td class="p-3"><?= e($payment['currency']) ?> <?= (int)$payment['amount'] ?></td><td class="p-3"><?= e($payment['status']) ?><?php if (str_starts_with((string) ($payment['provider_reference'] ?? ''), 'SANDBOX-TEST-')): ?> <span class="text-amber-800">(sandbox simulated; no charge)</span><?php endif; ?></td><td class="p-3"><?= e($payment['created_at']) ?></td></tr><?php endforeach; ?></tbody></table></div></section>
    <?php else: ?>
        <div class="rounded-2xl bg-white p-10 text-center"><h1 class="text-3xl font-black">Page not found</h1><a class="mt-5 inline-block text-brand-700" href="index.php">Return home</a></div>
    <?php endif; ?>
</main>
<footer class="mt-16 border-t border-slate-200 bg-white"><div class="max-w-6xl mx-auto px-5 py-6 text-sm text-slate-500">© <?= date('Y') ?> Masomo School · Learn with confidence.</div></footer>
</body>
</html>
