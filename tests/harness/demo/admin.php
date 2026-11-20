<?php

require_once __DIR__ . '/init.php';
require_once __DIR__ . '/modules/gateways/paystack.php';

function e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES);
}

$configFile = (string) getenv('HARNESS_DEMO_CONFIG');
$saved = json_decode((string) @file_get_contents($configFile), true);
$saved = is_array($saved) ? $saved : [];
$fields = paystack_config();

$notice = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submitted = [];
    foreach ($fields as $name => $field) {
        if ($field['Type'] === 'yesno') {
            $submitted[$name] = isset($_POST[$name]) ? 'on' : '';
        } elseif ($field['Type'] === 'text') {
            $submitted[$name] = isset($_POST[$name]) && is_string($_POST[$name]) ? $_POST[$name] : '';
        }
    }
    try {
        paystack_config_validate($submitted);
        file_put_contents($configFile, json_encode($submitted), LOCK_EX);
        $saved = $submitted;
        $notice = '<div class="alert alert-success">Changes saved</div>';
    } catch (\Throwable $error) {
        $saved = $submitted;
        $notice = '<div class="alert alert-danger">' . e($error->getMessage()) . '</div>';
    }
}
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<title>Payment Gateways — Paystack</title>
<style>
body { font-family: Arial, sans-serif; font-size: 13px; background: #f4f4f4; margin: 0; padding: 20px; }
.box { background: #fff; border: 1px solid #ddd; max-width: 900px; padding: 20px; }
h1 { font-size: 22px; font-weight: normal; margin: 0 0 15px; }
table { width: 100%; border-collapse: collapse; }
td { padding: 8px; border-top: 1px solid #eee; vertical-align: top; }
td.label { width: 200px; text-align: right; font-weight: bold; }
input[type=text] { padding: 4px; width: 320px; }
.desc { color: #777; margin-top: 4px; }
.alert { padding: 10px 15px; margin-bottom: 15px; border: 1px solid; }
.alert-danger { background: #f2dede; border-color: #ebccd1; color: #a94442; }
.alert-success { background: #dff0d8; border-color: #d6e9c6; color: #3c763d; }
.btn { padding: 6px 20px; }
</style>
</head>
<body>
<div class="box">
<h1>Payment Gateways — Paystack</h1>
<?php echo $notice; ?>
<form method="post" action="admin.php">
<table>
<?php foreach ($fields as $name => $field) { ?>
<tr>
<td class="label"><?php echo e($field['FriendlyName'] ?? $name); ?></td>
<td>
<?php if ($field['Type'] === 'System') { ?>
<?php echo e($field['Value'] ?? ''); ?>
<?php } elseif ($field['Type'] === 'yesno') { ?>
<input type="checkbox" name="<?php echo e($name); ?>" value="on"<?php echo ($saved[$name] ?? '') === 'on' ? ' checked' : ''; ?>>
<?php } else { ?>
<input type="text" name="<?php echo e($name); ?>" size="<?php echo e($field['Size'] ?? 32); ?>" value="<?php echo e($saved[$name] ?? ''); ?>">
<?php } ?>
<?php if (isset($field['Description'])) { ?>
<div class="desc"><?php echo $field['Description']; ?></div>
<?php } ?>
</td>
</tr>
<?php } ?>
</table>
<p><input class="btn" type="submit" value="Save Changes"></p>
</form>
</div>
</body>
</html>
