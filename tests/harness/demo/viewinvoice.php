<?php

header('Location: invoice.php?id=' . (int) ($_GET['id'] ?? 0));
