
<?php
session_start();

$conn = new mysqli("localhost", "root", "", "eventdb");

if ($conn->connect_error) {
    die("Database connection failed");
}

$msg = "";
$folder = "uploads/";

if (!is_dir($folder)) {
    mkdir($folder, 0755, true);
}

function uploadPoster($file, $old = "") {
    global $folder;

    if ($file['error'] == UPLOAD_ERR_NO_FILE) {
        return $old;
    }

    if ($file['error'] != UPLOAD_ERR_OK) {
        throw new Exception("Poster upload failed");
    }

    if ($file['size'] > 2 * 1024 * 1024) {
        throw new Exception("Poster must be under 2 MB");
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $type = $finfo->file($file['tmp_name']);

    $allowed = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp'
    ];

    if (!isset($allowed[$type])) {
        throw new Exception("Only JPG, PNG and WEBP allowed");
    }

    $name = bin2hex(random_bytes(16)) . "." . $allowed[$type];

    if (!move_uploaded_file($file['tmp_name'], $folder . $name)) {
        throw new Exception("Could not save poster");
    }

    if ($old != "" && is_file($folder . $old)) {
        unlink($folder . $old);
    }

    return $name;
}

if (isset($_POST['save'])) {
    try {
        $title = trim($_POST['title']);
        $description = trim($_POST['description']);
        $date = $_POST['event_date'];
        $category = $_POST['category'];
        $status = $_POST['status'];

        if ($title == "") {
            throw new Exception("Enter event title");
        }

        if (!in_array($status, ['Open', 'Closed']) ||
            !in_array($category, ['Technical', 'Sports', 'Cultural'])) {
            throw new Exception("Invalid category or status");
        }

        $poster = uploadPoster($_FILES['poster']);

        $stmt = $conn->prepare(
            "INSERT INTO events
            (title, description, event_date, category, status, poster)
            VALUES (?, ?, ?, ?, ?, ?)"
        );

        $stmt->bind_param(
            "ssssss",
            $title, $description, $date,
            $category, $status, $poster
        );

        if ($stmt->execute()) {
            $msg = "Event added successfully";
        } else {
            throw new Exception("Event not added");
        }

    } catch (Throwable $e) {
        $msg = $e->getMessage();
    }
}

if (isset($_POST['update'])) {
    try {
        $id = (int)$_POST['id'];
        $title = trim($_POST['title']);
        $description = trim($_POST['description']);
        $date = $_POST['event_date'];
        $category = $_POST['category'];
        $status = $_POST['status'];

        if ($title == "") {
            throw new Exception("Enter event title");
        }

        if (!in_array($status, ['Open', 'Closed']) ||
            !in_array($category, ['Technical', 'Sports', 'Cultural'])) {
            throw new Exception("Invalid category or status");
        }

        $stmt = $conn->prepare("SELECT poster FROM events WHERE id=?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $oldEvent = $stmt->get_result()->fetch_assoc();

        if (!$oldEvent) {
            throw new Exception("Event not found");
        }

        $poster = uploadPoster($_FILES['poster'], $oldEvent['poster']);

        $stmt = $conn->prepare(
            "UPDATE events SET title=?, description=?,
            event_date=?, category=?, status=?, poster=? WHERE id=?"
        );

        $stmt->bind_param(
            "ssssssi",
            $title, $description, $date,
            $category, $status, $poster, $id
        );

        if ($stmt->execute()) {
            $msg = "Event updated successfully";
        } else {
            throw new Exception("Event update failed");
        }

    } catch (Throwable $e) {
        $msg = $e->getMessage();
    }
}

if (isset($_POST['delete'])) {
    $id = (int)$_POST['id'];

    $stmt = $conn->prepare("SELECT poster FROM events WHERE id=?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $event = $stmt->get_result()->fetch_assoc();

    if ($event) {
        $stmt = $conn->prepare("DELETE FROM events WHERE id=?");
        $stmt->bind_param("i", $id);

        if ($stmt->execute()) {
            if ($event['poster'] != "" &&
                is_file($folder . $event['poster'])) {
                unlink($folder . $event['poster']);
            }

            $msg = "Event deleted successfully";
        } else {
            $msg = "Delete failed";
        }
    } else {
        $msg = "Event not found";
    }
}

$edit = null;

if (isset($_GET['edit'])) {
    $id = (int)$_GET['edit'];
    $stmt = $conn->prepare("SELECT * FROM events WHERE id=?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $edit = $stmt->get_result()->fetch_assoc();
}

$filter = $_GET['status'] ?? "";

if (!in_array($filter, ['', 'Open', 'Closed'])) {
    $filter = "";
}

$stmt = $conn->prepare(
    "SELECT * FROM events
    WHERE status LIKE ?
    ORDER BY id DESC"
);

$searchStatus = $filter == "" ? "%" : $filter;
$stmt->bind_param("s", $searchStatus);
$stmt->execute();
$result = $stmt->get_result();

function e($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Event Management</title>
</head>
<body>

<h2>Event Management System</h2>

<p><?php echo e($msg); ?></p>

<h3><?php echo $edit ? "Edit Event" : "Add Event"; ?></h3>

<form method="post" enctype="multipart/form-data">

    <input type="hidden" name="id"
    value="<?php echo e($edit['id'] ?? ''); ?>">

    Title:
    <input type="text" name="title" required
    value="<?php echo e($edit['title'] ?? ''); ?>">
    <br><br>

    Description:
    <textarea name="description"><?php
        echo e($edit['description'] ?? '');
    ?></textarea>
    <br><br>

    Date:
    <input type="date" name="event_date" required
    value="<?php echo e($edit['event_date'] ?? ''); ?>">
    <br><br>

    Category:
    <select name="category" required>
        <?php
        $categories = ['Technical', 'Sports', 'Cultural'];
        foreach ($categories as $c) {
            $selected = (($edit['category'] ?? '') == $c)
                ? 'selected' : '';
            echo "<option value='" . e($c) . "' $selected>"
                . e($c) . "</option>";
        }
        ?>
    </select>
    <br><br>

    Status:
    <select name="status">
        <option value="Open"
        <?php if (($edit['status'] ?? 'Open') == 'Open')
            echo 'selected'; ?>>Open</option>

        <option value="Closed"
        <?php if (($edit['status'] ?? '') == 'Closed')
            echo 'selected'; ?>>Closed</option>
    </select>
    <br><br>

    Poster:
    <input type="file" name="poster"
    accept=".jpg,.jpeg,.png,.webp">
    <small>JPG, PNG or WEBP; maximum 2 MB</small>
    <br><br>

    <?php if ($edit) { ?>
        <button type="submit" name="update">Update Event</button>
        <a href="events.php">Cancel</a>
    <?php } else { ?>
        <button type="submit" name="save">Add Event</button>
    <?php } ?>

</form>

<hr>

<h3>Filter Events</h3>

<form method="get">
    <select name="status">
        <option value="">All Events</option>
        <option value="Open"
        <?php if ($filter == 'Open') echo 'selected'; ?>>Open</option>
        <option value="Closed"
        <?php if ($filter == 'Closed') echo 'selected'; ?>>Closed</option>
    </select>

    <button type="submit">Filter</button>
    <a href="events.php">Reset</a>
</form>

<h3>Event List</h3>

<table border="1" cellpadding="8">
<tr>
    <th>ID</th>
    <th>Title</th>
    <th>Date</th>
    <th>Category</th>
    <th>Status</th>
    <th>Poster</th>
    <th>Action</th>
</tr>

<?php while ($row = $result->fetch_assoc()) { ?>
<tr>
    <td><?php echo e($row['id']); ?></td>
    <td><?php echo e($row['title']); ?></td>
    <td><?php echo e($row['event_date']); ?></td>
    <td><?php echo e($row['category']); ?></td>
    <td><?php echo e($row['status']); ?></td>

    <td>
        <?php if ($row['poster'] != "") { ?>
            <img src="uploads/<?php echo e($row['poster']); ?>"
            width="80" alt="Event poster">
        <?php } else { ?>
            No poster
        <?php } ?>
    </td>

    <td>
        <a href="events.php?edit=<?php echo e($row['id']); ?>">
            Edit
        </a>

        <form method="post" style="display:inline"
        onsubmit="return confirm('Delete this event?');">

            <input type="hidden" name="id"
            value="<?php echo e($row['id']); ?>">

            <button type="submit" name="delete">Delete</button>
        </form>
    </td>
</tr>
<?php } ?>

</table>

</body>
</html>
