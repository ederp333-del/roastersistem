<?php
if (session_status() == PHP_SESSION_NONE) session_start();

$conexion = new mysqli("localhost", "u413611561_estucafe", "@Dios2025", "u413611561_estucafe");
if ($conexion->connect_error) die("Error de conexión");

// Obtener sucursal y nombre/código del usuario (empleado)
$sucursal_usuario = "";
$nombre_empleado = "";
$codigo_empleado = "";
if (isset($_SESSION['id'])) {
    $codigo_empleado = $_SESSION['id'];
    $stmt = $conexion->prepare("SELECT sucursal, nombre FROM usuarios WHERE id = ?");
    $stmt->bind_param("i", $_SESSION['id']);
    $stmt->execute();
    $stmt->bind_result($sucursal_db, $nombre_db);
    if ($stmt->fetch()) {
        $sucursal_usuario = $sucursal_db;
        $nombre_empleado = $nombre_db;
    }
    $stmt->close();
}

// Obtener todos los empaques disponibles en la sucursal
$empaques = [];
$stmt_emp = $conexion->prepare("SELECT codigo, descripcion FROM inventarioemp WHERE sucursal = ?");
$stmt_emp->bind_param("s", $sucursal_usuario);
$stmt_emp->execute();
$res_emp = $stmt_emp->get_result();
while ($row_emp = $res_emp->fetch_assoc()) {
    $empaques[$row_emp['codigo']] = $row_emp['descripcion'];
}
$stmt_emp->close();

$id_orden = isset($_GET['id']) ? intval($_GET['id']) : 0;
$orden = [];
if ($id_orden) {
    $sql = $conexion->prepare("SELECT * FROM ingresos WHERE numero_ingreso = ? AND sucursal = ?");
    $sql->bind_param("is", $id_orden, $sucursal_usuario);
    $sql->execute();
    $res = $sql->get_result();
    $orden = $res->fetch_assoc();
    $sql->close();

    // Si el peso_neto viene como número, formatearlo para mostrar decimales en el input
    if (!empty($orden) && isset($orden['peso_neto'])) {
        // Mantener hasta 3 decimales y eliminar ceros/trailing dot si no son necesarios
        $orden['peso_neto'] = rtrim(rtrim(number_format((float)$orden['peso_neto'], 3, '.', ''), '0'), '.');
    }
}

// Si no existe orden, redirigir
if (!$orden) {
    echo "<script>alert('Orden no encontrada o no pertenece a su sucursal.'); window.location.href='index.php?vista=Ordenes';</script>";
    exit;
}

// Determina si la vista debe ser solo lectura (orden COMPLETADA)
$solo_lectura = false;
if (isset($orden['estado_ingreso']) && strtoupper($orden['estado_ingreso']) === 'COMPLETADA') {
    $solo_lectura = true;
}

// Detalle pedido
$detalle_pedido_db = [];
if ($id_orden) {
    $stmt = $conexion->prepare("SELECT * FROM detalle_pedido WHERE numero_ingreso = ? AND sucursal = ?");
    $stmt->bind_param("is", $id_orden, $sucursal_usuario);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) $detalle_pedido_db[] = $row;
    $stmt->close();

    // Formatear cantidades para que se muestren con decimales (preservando hasta 3 decimales)
    unset($d);
}

// Obtener sacos (Ingreso_sacos) para esta orden/sucursal
$sacos = [];
$totalPesoSacos = 0.0;
if ($id_orden) {
    $stmt = $conexion->prepare("SELECT id, No_Saco, Variedad, Altura, Humedad, Estado_cafe, peso_bruto, tara, peso_neto_cafe FROM Ingreso_sacos WHERE no_ingreso = ? AND sucursal = ? ORDER BY No_Saco ASC");
    $stmt->bind_param("is", $id_orden, $sucursal_usuario);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($r = $res->fetch_assoc()) {
        $r['peso_bruto'] = isset($r['peso_bruto']) ? floatval($r['peso_bruto']) : 0.0;
        $r['tara'] = isset($r['tara']) ? floatval($r['tara']) : 0.0;
        $r['peso_neto_cafe'] = isset($r['peso_neto_cafe']) ? floatval($r['peso_neto_cafe']) : 0.0;
        $totalPesoSacos += $r['peso_neto_cafe'];
        $sacos[] = $r;
    }
    $stmt->close();
}

// A partir de ahora, el campo peso_neto del formulario debe reflejar la SUMA de los sacos
// Mostramos $totalPesoSacos en el input peso_neto y también mantenemos variable para servidor
$totalPesoSacos = round((float)$totalPesoSacos, 3);

// --- NUEVO: permitir actualizar solo los pesos netos de sacos cuando corresponda ---
// Solo permitimos actualizar si la orden NO está en modo solo_lectura (aunque la tabla se muestra siempre en modo lectura)
if (!$solo_lectura && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['guardar_pesos_sacos']) && isset($_POST['sacos']) && is_array($_POST['sacos'])) {
    $postedSacos = $_POST['sacos'];
    $errores_sacos = [];
    $updates = [];

    foreach ($postedSacos as $idx => $s) {
        $id_saco = isset($s['id']) ? intval($s['id']) : 0;
        if ($id_saco === 0) {
            $errores_sacos[] = "Fila #".($idx+1).": id de saco inválido.";
            continue;
        }
        if (!isset($sacosById[$id_saco])) {
            $errores_sacos[] = "Fila #".($idx+1).": saco no encontrado.";
            continue;
        }
        $found = $sacosById[$id_saco];
        $estado = strtolower(trim($found['Estado_cafe'] ?? ''));
        if (!in_array($estado, ['pergamino', 'pergamino seco', 'cereza'])) {
            // no permitir cambios, skip silently
            continue;
        }

        $pn_raw = isset($s['peso_neto_cafe']) ? trim(str_replace(',', '.', $s['peso_neto_cafe'])) : '';
        if ($pn_raw === '') {
            // Accept NULL: user cleared the field => set to NULL
            $pn_val = null;
        } else {
            if (!is_numeric($pn_raw)) {
                $errores_sacos[] = "Fila #".($idx+1).": peso neto inválido.";
                continue;
            }
            $pn_val = floatval($pn_raw);
            if ($pn_val < 0) {
                $errores_sacos[] = "Fila #".($idx+1).": peso neto no puede ser negativo.";
                continue;
            }
            $max_possible = ($found['peso_bruto'] - $found['tara']);
            if ($max_possible < 0) $max_possible = 0;
            if ($pn_val > $max_possible + 0.001) {
                $errores_sacos[] = "Fila #".($idx+1).": peso neto ({$pn_val}) no puede ser mayor que peso bruto - tara ({$max_possible}).";
                continue;
            }
        }

        $updates[] = ['id' => $id_saco, 'peso_neto' => $pn_val];
    }

    if (empty($errores_sacos)) {
        $conexion->begin_transaction();
        try {
            // prepare statements once
            $stmt_up_null = $conexion->prepare("UPDATE Ingreso_sacos SET peso_neto_cafe = NULL, peso_ingreso = NULL WHERE id = ? AND sucursal = ?");
            $stmt_up_num  = $conexion->prepare("UPDATE Ingreso_sacos SET peso_neto_cafe = ?, peso_ingreso = ? WHERE id = ? AND sucursal = ?");
            if (!$stmt_up_null || !$stmt_up_num) {
                throw new Exception("Error al preparar statements de actualización: " . $conexion->error);
            }

            foreach ($updates as $u) {
                if ($u['peso_neto'] === null) {
                    // bind int,string
                    $stmt_up_null->bind_param("is", $u['id'], $sucursal_usuario);
                    if (!$stmt_up_null->execute()) throw new Exception("Error al actualizar (NULL) saco id {$u['id']}: " . $stmt_up_null->error);
                } else {
                    // bind double,double,int,string => "ddis"
                    $stmt_up_num->bind_param("ddis", $u['peso_neto'], $u['peso_neto'], $u['id'], $sucursal_usuario);
                    if (!$stmt_up_num->execute()) throw new Exception("Error al actualizar saco id {$u['id']}: " . $stmt_up_num->error);
                }
            }
            $stmt_up_null->close();
            $stmt_up_num->close();

            // Recalcular total y actualizar tabla ingresos
            $stmt_sum = $conexion->prepare("SELECT IFNULL(SUM(peso_neto_cafe),0) AS total FROM Ingreso_sacos WHERE no_ingreso = ? AND sucursal = ?");
            $stmt_sum->bind_param("is", $id_orden, $sucursal_usuario);
            $stmt_sum->execute();
            $stmt_sum->bind_result($nuevoTotal);
            $stmt_sum->fetch();
            $stmt_sum->close();
            $nuevoTotal = floatval($nuevoTotal);

            $stmt_up_ing = $conexion->prepare("UPDATE ingresos SET peso_neto = ? WHERE numero_ingreso = ? AND sucursal = ?");
            $stmt_up_ing->bind_param("dis", $nuevoTotal, $id_orden, $sucursal_usuario);
            $stmt_up_ing->execute();
            $stmt_up_ing->close();

            $conexion->commit();
            $mensajes[] = "Pesos de sacos actualizados correctamente.";

            // recargar sacos y sum
            $sacos = [];
            $totalPesoSacos = 0.0;
            $stmt = $conexion->prepare("SELECT id, No_Saco, Variedad, Altura, Humedad, Estado_cafe, peso_bruto, tara, peso_neto_cafe FROM Ingreso_sacos WHERE no_ingreso = ? AND sucursal = ? ORDER BY No_Saco ASC");
            $stmt->bind_param("is", $id_orden, $sucursal_usuario);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($r = $res->fetch_assoc()) {
                $r['peso_bruto'] = isset($r['peso_bruto']) ? floatval($r['peso_bruto']) : 0.0;
                $r['tara'] = isset($r['tara']) ? floatval($r['tara']) : 0.0;
                $r['peso_neto_cafe'] = array_key_exists('peso_neto_cafe', $r) && $r['peso_neto_cafe'] !== null ? floatval($r['peso_neto_cafe']) : null;
                if ($r['peso_neto_cafe'] !== null) $totalPesoSacos += $r['peso_neto_cafe'];
                $sacos[] = $r;
            }
            $stmt->close();
            $totalPesoSacos = round((float)$totalPesoSacos, 3);

        } catch (Exception $e) {
            $conexion->rollback();
            $errores_sacos[] = "Error al guardar cambios: " . $e->getMessage();
        }
    }

    if (!empty($errores_sacos)) {
        foreach ($errores_sacos as $er) $errores[] = $er;
    }
}

// --- BLOQUE PARA EDITAR DETALLE PEDIDO (ACTUALIZADO: ajustar inventario según diferencia, admitir decimales) ---
// Solo permitir edición si no es solo lectura
$errores = [];
if (!$solo_lectura && isset($_POST['guardar_edicion_detalle']) && isset($_POST['detalle_pedido'])) {
    $detalles_editados = $_POST['detalle_pedido'];

    // Validaciones iniciales de inputs (cantidad > 0, unidad no vacía)
    foreach ($detalles_editados as $d_idx => $d) {
        // aceptar formato con coma
        $cantidad = isset($d['cantidad']) ? floatval(str_replace(',', '.', $d['cantidad'])) : 0.0;
        $unidad = isset($d['unidad']) ? trim($d['unidad']) : '';
        if ($cantidad <= 0) {
            $errores[] = "La cantidad en la fila #" . ($d_idx+1) . " debe ser mayor a cero.";
        }
        if ($unidad === '') {
            $errores[] = "La unidad en la fila #" . ($d_idx+1) . " no puede estar vacía.";
        }
    }

    if (empty($errores)) {
        // Iniciar transacción para realizar ajustes atómicos en detalle_pedido e inventarioemp
        $conexion->begin_transaction();

        try {
            // Preparar declaraciones reutilizables
            $stmt_select_det = $conexion->prepare("SELECT cantidad, codigo_empaque FROM detalle_pedido WHERE id = ? AND numero_ingreso = ? AND sucursal = ? FOR UPDATE");
            $stmt_update_det = $conexion->prepare("UPDATE detalle_pedido SET cantidad = ?, unidad = ?, codigo_empaque = ?, granel = ?, vena = ?, molido = ?, grano = ? WHERE id = ? AND numero_ingreso = ? AND sucursal = ?");

            // Para consultar inventario y bloquear fila
            $stmt_select_inv = $conexion->prepare("SELECT total_inventario FROM inventarioemp WHERE codigo = ? AND sucursal = ? FOR UPDATE");
            // actualizar inventario con parámetros decimales
            $stmt_update_inv_add = $conexion->prepare("UPDATE inventarioemp SET total_inventario = total_inventario + ? WHERE codigo = ? AND sucursal = ?");
            $stmt_update_inv_sub = $conexion->prepare("UPDATE inventarioemp SET total_inventario = total_inventario - ? WHERE codigo = ? AND sucursal = ? AND total_inventario >= ?");

            if (!$stmt_select_det || !$stmt_update_det || !$stmt_select_inv || !$stmt_update_inv_add || !$stmt_update_inv_sub) {
                throw new Exception("Error al preparar sentencias: " . $conexion->error);
            }

            foreach ($detalles_editados as $detalle_editado) {
                $cantidad_nueva = floatval(str_replace(',', '.', $detalle_editado['cantidad']));
                $unidad = trim($detalle_editado['unidad']);
                $codigo_empaque_nuevo = trim($detalle_editado['codigo_empaque']);
                $granel = isset($detalle_editado['granel']) ? 1 : 0;
                $vena = isset($detalle_editado['vena']) ? 1 : 0;
                $molido = isset($detalle_editado['molido']) ? 1 : 0;
                $grano = isset($detalle_editado['grano']) ? 1 : 0;
                $id_detalle = intval($detalle_editado['id']);

                // Obtener cantidad y empaque anterior y bloquear la fila del detalle
                $stmt_select_det->bind_param("iis", $id_detalle, $id_orden, $sucursal_usuario);
                if (!$stmt_select_det->execute()) throw new Exception("Error al obtener detalle: " . $conexion->error);
                $stmt_select_det->bind_result($cantidad_anterior, $codigo_empaque_anterior);
                if (!$stmt_select_det->fetch()) {
                    // No se encontró el detalle (posible inconsistencia)
                    $stmt_select_det->close();
                    throw new Exception("Detalle con id {$id_detalle} no encontrado para la orden.");
                }
                // convertir cantidad anterior a float para cálculos decimales
                $cantidad_anterior = floatval($cantidad_anterior);
                // liberar fetch pero mantener bloqueo sobre la fila de detalle
                $stmt_select_det->free_result();

                // Si el usuario cambió el código de empaque
                if ($codigo_empaque_nuevo !== $codigo_empaque_anterior) {
                    // Para evitar deadlocks, bloqueamos inventarios en orden lexicográfico por código
                    $codesToLock = [$codigo_empaque_anterior, $codigo_empaque_nuevo];
                    sort($codesToLock, SORT_STRING);

                    $inventarios = []; // codigo => existencia_actual (float)

                    // Seleccionar y bloquear cada inventario (si existe)
                    foreach ($codesToLock as $codeLock) {
                        $stmt_select_inv->bind_param("ss", $codeLock, $sucursal_usuario);
                        if (!$stmt_select_inv->execute()) throw new Exception("Error al obtener inventario para código {$codeLock}: " . $conexion->error);
                        $stmt_select_inv->bind_result($existencia_tmp);
                        if ($stmt_select_inv->fetch()) {
                            // convertir a float (aceptar decimales)
                            $inventarios[$codeLock] = floatval($existencia_tmp);
                            $stmt_select_inv->free_result();
                        } else {
                            // Si la fila de inventario no existe, y es el nuevo código (al querer restar), es error
                            $stmt_select_inv->free_result();
                            throw new Exception("No existe registro de inventario para el empaque código '{$codeLock}' en la sucursal '{$sucursal_usuario}'.");
                        }
                    }

                    // Primero: devolver al inventario antiguo la cantidad anterior
                    // usar tipo double para la cantidad
                    $stmt_update_inv_add->bind_param("dss", $cantidad_anterior, $codigo_empaque_anterior, $sucursal_usuario);
                    if (!$stmt_update_inv_add->execute()) throw new Exception("Error al devolver inventario para código {$codigo_empaque_anterior}: " . $conexion->error);

                    // Luego: restar la cantidad nueva del inventario del nuevo empaque (validando existencia)
                    $existencia_nuevo_actual = isset($inventarios[$codigo_empaque_nuevo]) ? floatval($inventarios[$codigo_empaque_nuevo]) : null;
                    if ($existencia_nuevo_actual === null) {
                        throw new Exception("No se puede acceder al inventario del nuevo empaque {$codigo_empaque_nuevo}.");
                    }
                    if ($cantidad_nueva > $existencia_nuevo_actual) {
                        throw new Exception("No hay suficiente inventario en el empaque {$codigo_empaque_nuevo}. Disponible: {$existencia_nuevo_actual}, solicitado: {$cantidad_nueva}.");
                    }
                    // Restar (double, string, string, double)
                    $stmt_update_inv_sub->bind_param("dssd", $cantidad_nueva, $codigo_empaque_nuevo, $sucursal_usuario, $cantidad_nueva);
                    if (!$stmt_update_inv_sub->execute()) throw new Exception("Error al restar inventario para código {$codigo_empaque_nuevo}: " . $conexion->error);
                    if ($stmt_update_inv_sub->affected_rows === 0) {
                        throw new Exception("No se pudo restar inventario para código {$codigo_empaque_nuevo} (quizá existencias insuficientes).");
                    }

                } else {
                    // Mismo empaque: calcular diferencia
                    $diff = $cantidad_nueva - floatval($cantidad_anterior);
                    if ($diff == 0) {
                        // No hay cambio en inventario, solo actualizar detalle
                    } elseif ($diff > 0) {
                        // Se debe restar diff del inventario
                        $stmt_select_inv->bind_param("ss", $codigo_empaque_nuevo, $sucursal_usuario);
                        if (!$stmt_select_inv->execute()) throw new Exception("Error al obtener inventario para código {$codigo_empaque_nuevo}: " . $conexion->error);
                        $stmt_select_inv->bind_result($existencia_actual);
                        if ($stmt_select_inv->fetch()) {
                            // convertir a float (aceptar decimales)
                            $existencia_actual = floatval($existencia_actual);
                            $stmt_select_inv->free_result();
                        } else {
                            $stmt_select_inv->free_result();
                            throw new Exception("No existe inventario para el empaque código {$codigo_empaque_nuevo} en la sucursal {$sucursal_usuario}.");
                        }

                        if ($diff > $existencia_actual) {
                            throw new Exception("Empaque código {$codigo_empaque_nuevo} solo tiene {$existencia_actual} en inventario, se requieren {$diff} adicionales.");
                        }
                        // Restar diff (usar tipos con double)
                        $stmt_update_inv_sub->bind_param("dssd", $diff, $codigo_empaque_nuevo, $sucursal_usuario, $diff);
                        if (!$stmt_update_inv_sub->execute()) throw new Exception("Error al restar inventario para código {$codigo_empaque_nuevo}: " . $conexion->error);
                        if ($stmt_update_inv_sub->affected_rows === 0) {
                            throw new Exception("No se pudo actualizar inventario para código {$codigo_empaque_nuevo} (quizá existencias insuficientes).");
                        }
                    } else { // diff < 0
                        $toAdd = floatval(abs($diff));
                        // Sumar la diferencia al inventario (double)
                        $stmt_update_inv_add->bind_param("dss", $toAdd, $codigo_empaque_nuevo, $sucursal_usuario);
                        if (!$stmt_update_inv_add->execute()) throw new Exception("Error al devolver inventario para código {$codigo_empaque_nuevo}: " . $conexion->error);
                    }
                }

                // Finalmente actualizar la fila detalle_pedido con los nuevos datos
                $stmt_update_det->bind_param(
                    "dssiiiiiss",
                    $cantidad_nueva,
                    $unidad,
                    $codigo_empaque_nuevo,
                    $granel,
                    $vena,
                    $molido,
                    $grano,
                    $id_detalle,
                    $id_orden,
                    $sucursal_usuario
                );
                if (!$stmt_update_det->execute()) {
                    throw new Exception("Error al actualizar detalle_pedido (id {$id_detalle}): " . $conexion->error);
                }
            }

            // Cerrar statements
            $stmt_select_det->close();
            $stmt_update_det->close();
            $stmt_select_inv->close();
            $stmt_update_inv_add->close();
            $stmt_update_inv_sub->close();

            // Commit si todo salió bien
            $conexion->commit();
            echo "<script>alert('Detalle del pedido actualizado correctamente y el inventario ajustado.');</script>";

        } catch (Exception $e) {
            // En caso de error hacer rollback y mostrar mensaje
            $conexion->rollback();
            $msg = addslashes($e->getMessage());
            $errores[] = "No se pudo actualizar detalle: " . $msg;
        }
    }
}
// --- FIN BLOQUE EDITAR DETALLE PEDIDO ---


// --- AGREGADO PARA PAUSA --- guardar avance SIN eliminar nada de tu código
if (!$solo_lectura && isset($_POST['guardar_avance'])) {
    // Obtener el peso neto enviado (si existe) o fallback al valor actual de la orden
    $peso_neto_post = isset($_POST['peso_neto']) && $_POST['peso_neto'] !== '' ? floatval(str_replace(',', '.', $_POST['peso_neto'])) : (isset($orden['peso_neto']) ? floatval($orden['peso_neto']) : 0.0);

    // Actualizar ingresos.peso_neto con el valor enviado (nota: actualiza tabla ingresos)
    $stmt_up_peso = $conexion->prepare("UPDATE ingresos SET peso_neto = ? WHERE numero_ingreso = ? AND sucursal = ?");
    if ($stmt_up_peso) {
        $stmt_up_peso->bind_param("dis", $peso_neto_post, $id_orden, $sucursal_usuario);
        $stmt_up_peso->execute();
        $stmt_up_peso->close();
    }

    // Borra los registros en PAUSA/NULL y vuelve a insertar los enviados
    $conexion->query("DELETE FROM detalle_tueste WHERE id_orden = $id_orden AND sucursal = '$sucursal_usuario' AND (estado IS NULL OR estado='PAUSA')");
    if (isset($_POST['tueste']) && is_array($_POST['tueste'])) {
        $bach_actual = 0;

        $nombre_empleado_guardar = isset($_POST['nombre_empleado']) ? trim($_POST['nombre_empleado']) : $nombre_empleado;
        $codigo_empleado_guardar = isset($_POST['codigo_empleado']) ? trim($_POST['codigo_empleado']) : $codigo_empleado;
        $observaciones = isset($_POST['observaciones']) ? $_POST['observaciones'] : "";
        $humedad_cafe = isset($_POST['humedad_cafe']) ? floatval($_POST['humedad_cafe']) : null;
        $humedad_relativa = isset($_POST['humedad_relativa']) ? floatval($_POST['humedad_relativa']) : null;
        $temperatura_ambiente = isset($_POST['temperatura_ambiente']) ? floatval($_POST['temperatura_ambiente']) : null;

        foreach ($_POST['tueste'] as $tueste) {
            $bach_actual++;
            $lv = isset($tueste['lv']) ? $tueste['lv'] : '';
            $lt = isset($tueste['lt']) ? $tueste['lt'] : '';
            $ti = isset($tueste['ti']) ? $tueste['ti'] : '';
            $pc = isset($tueste['pc']) ? $tueste['pc'] : '';
            $minutos = isset($tueste['minutos']) ? intval($tueste['minutos']) : null;
            $tk = isset($tueste['tk']) ? $tueste['tk'] : '';
            $tf = isset($tueste['tf']) ? $tueste['tf'] : '';
            $tt = isset($tueste['tt']) ? $tueste['tt'] : '';
            $rt = '';
            if (is_numeric($lv) && is_numeric($lt) && floatval($lt) != 0) {
                $rt = round(floatval($lv) / floatval($lt), 2);
            }
            $estado = 'PAUSA';
            $stmt = $conexion->prepare("INSERT INTO detalle_tueste 
                (id_orden, sucursal, bach, lv, lt, ti, pc, minutos, tk, tf, tt, rt, estado,
                NombreEmpleado, codigoEmpleado, Observaciones, HumedadCafe, HumedadRelativa, TemperaturaAmbiente, peso_neto)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            if ($stmt === false) {
                // Preparación fallida: registra error y continúa (evita romper la página)
                $errores[] = "Error al preparar INSERT detalle_tueste: " . $conexion->error;
                continue;
            }
            $stmt->bind_param(
                "isissssissddssssdddd",
                $id_orden,
                $sucursal_usuario,
                $bach_actual,
                $lv,
                $lt,
                $ti,
                $pc,
                $minutos,
                $tk,
                $tf,
                $tt,
                $rt,
                $estado,
                $nombre_empleado_guardar,
                $codigo_empleado_guardar,
                $observaciones,
                $humedad_cafe,
                $humedad_relativa,
                $temperatura_ambiente,
                $peso_neto_post
            );
            $stmt->execute();
            $stmt->close();
        }
    }
    echo "<script>alert('Avance guardado. Puedes continuar la orden más tarde.');window.location.href='index.php?vista=Ordenes';</script>";
    exit;
}
// --- FIN AGREGADO PARA PAUSA ---

if (!$solo_lectura && isset($_POST['guardar_orden'])) {
    $tuestes_validos = [];
    $peso_neto = isset($orden['peso_neto']) ? floatval($orden['peso_neto']) : 0.0;
    $total_lv = 0.0;
    if (isset($_POST['tueste']) && is_array($_POST['tueste'])) {
        foreach ($_POST['tueste'] as $idx => $tueste) {
            $lv = isset($tueste['lv']) ? trim($tueste['lv']) : '';
            $lt = isset($tueste['lt']) ? trim($tueste['lt']) : '';
            $minutos = isset($tueste['minutos']) ? trim($tueste['minutos']) : '';
            $lv_val = is_numeric($lv) ? floatval(str_replace(',', '.', $lv)) : 0.0;
            $total_lv += $lv_val;
            if ($lv === '' || $lt === '' || $minutos === '') {
                $errores[] = "Debes llenar LV, LT y Minutos en cada fila de tueste.";
                continue;
            }
            if ($lv_val > $peso_neto) {
                $errores[] = "No puedes ingresar en LV más del peso neto disponible (" . $peso_neto . " kg).";
                continue;
            }
            $tuestes_validos[] = $tueste;
        }
        if ($total_lv > $peso_neto) {
            $errores[] = "La suma total de LV no puede ser mayor al peso neto (" . $peso_neto . " kg).";
        }
    } else {
        $errores[] = "Debes agregar al menos una fila en tueste.";
    }

    if (count($errores) === 0) {
        $stmt_bach = $conexion->prepare("SELECT MAX(bach) FROM detalle_tueste WHERE id_orden = ?");
        $stmt_bach->bind_param("i", $id_orden);
        $stmt_bach->execute();
        $stmt_bach->bind_result($bach_ultimo);
        $stmt_bach->fetch();
        $stmt_bach->close();

        $bach_actual = ($bach_ultimo !== null && $bach_ultimo !== '') ? intval($bach_ultimo) : 0;

        $nombre_empleado_guardar = isset($_POST['nombre_empleado']) ? trim($_POST['nombre_empleado']) : $nombre_empleado;
        $codigo_empleado_guardar = isset($_POST['codigo_empleado']) ? trim($_POST['codigo_empleado']) : $codigo_empleado;
        $observaciones = isset($_POST['observaciones']) ? $_POST['observaciones'] : "";
        $humedad_cafe = isset($_POST['humedad_cafe']) ? floatval($_POST['humedad_cafe']) : null;
        $humedad_relativa = isset($_POST['humedad_relativa']) ? floatval($_POST['humedad_relativa']) : null;
        $temperatura_ambiente = isset($_POST['temperatura_ambiente']) ? floatval($_POST['temperatura_ambiente']) : null;

        // Obtener peso_neto enviado (o fallback)
        $peso_neto_post = isset($_POST['peso_neto']) && $_POST['peso_neto'] !== '' ? floatval(str_replace(',', '.', $_POST['peso_neto'])) : (isset($orden['peso_neto']) ? floatval($orden['peso_neto']) : 0.0);

        // Actualizar ingresos.peso_neto con el valor enviado
        $stmt_up_peso2 = $conexion->prepare("UPDATE ingresos SET peso_neto = ? WHERE numero_ingreso = ? AND sucursal = ?");
        if ($stmt_up_peso2) {
            $stmt_up_peso2->bind_param("dis", $peso_neto_post, $id_orden, $sucursal_usuario);
            $stmt_up_peso2->execute();
            $stmt_up_peso2->close();
        }

        foreach ($tuestes_validos as $tueste) {
            $bach_actual++;
            $lv       = isset($tueste['lv']) ? $tueste['lv'] : '';
            $lt       = isset($tueste['lt']) ? $tueste['lt'] : '';
            $ti       = isset($tueste['ti']) ? $tueste['ti'] : '';
            $pc       = isset($tueste['pc']) ? $tueste['pc'] : '';
            $minutos  = isset($tueste['minutos']) ? intval($tueste['minutos']) : null;
            $tk       = isset($tueste['tk']) ? $tueste['tk'] : '';
            $tf       = isset($tueste['tf']) ? $tueste['tf'] : '';
            $tt       = isset($tueste['tt']) ? $tueste['tt'] : '';
            $rt = '';
            if (is_numeric($lv) && is_numeric($lt) && floatval($lt) != 0) {
                $rt = round(floatval($lv) / floatval($lt), 2);
            }
            $estado = 'FINAL';
            $stmt = $conexion->prepare("INSERT INTO detalle_tueste 
                (id_orden, sucursal, bach, lv, lt, ti, pc, minutos, tk, tf, tt, rt, estado,
                NombreEmpleado, codigoEmpleado, Observaciones, HumedadCafe, HumedadRelativa, TemperaturaAmbiente, peso_neto)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            if ($stmt === false) {
                $errores[] = "Error al preparar INSERT detalle_tueste: " . $conexion->error;
                continue;
            }
            $stmt->bind_param(
                "isissssissddssssdddd",
                $id_orden,
                $sucursal_usuario,
                $bach_actual,
                $lv,
                $lt,
                $ti,
                $pc,
                $minutos,
                $tk,
                $tf,
                $tt,
                $rt,
                $estado,
                $nombre_empleado_guardar,
                $codigo_empleado_guardar,
                $observaciones,
                $humedad_cafe,
                $humedad_relativa,
                $temperatura_ambiente,
                $peso_neto_post
            );
            $stmt->execute();
            $stmt->close();
        }

        $stmt_update = $conexion->prepare("UPDATE ingresos SET estado_ingreso = 'COMPLETADA' WHERE numero_ingreso = ? AND sucursal = ?");
        $stmt_update->bind_param("is", $id_orden, $sucursal_usuario);
        $stmt_update->execute();
        $stmt_update->close();

        echo "<script>alert('Orden guardada exitosamente');window.location.href='index.php?vista=Ordenes';</script>";
        exit;
    }
}

?>

<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>Orden de Trabajo Estrucafé</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
<style>
  body {
    background: #f7e9d6;
    font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
 }
 .talonario-info-flex {
  display: flex;
  flex-wrap: wrap;
  margin-bottom: 20px;
  gap: 32px;
  align-items: flex-start;
}
.talonario-info-flex .info-col {
  flex: 2 1 320px;
  min-width: 200px;
}
.talonario-info-flex .input-col {
  flex: 1 1 250px;
  min-width: 260px;
  max-width: 340px;
  display: flex;
  flex-direction: column;
  gap: 18px;
  margin-top: 6px;
}
.input-col label {
  font-weight: 600;
  color: #222;
  margin-bottom: 6px;
  font-size: 15px;
}
.input-col input[type="text"] {
  background: #f8fafd;
  color: #222;
  border: 1.5px solid #bbb;
  border-radius: 8px;
  padding: 9px 14px;
  font-size: 15px;
  transition: border-color 0.2s, box-shadow 0.2s;
  width: 100%;
}
.input-col input[type="text"]:focus {
  border-color: #1565c0;
  background: #fff;
  box-shadow: 0 0 7px rgba(21,101,192,0.13);
}
@media (max-width: 900px) {
  .talonario-info-flex {
    flex-direction: column;
    gap: 10px;
  }
  .talonario-info-flex .input-col,
  .talonario-info-flex .info-col {
    min-width: 100%;
    max-width: 100%;
  }
}
  .talonario-container {
    background: #fff;
    max-width: 1000px;
    margin: 30px auto;
    border-radius: 14px;
    box-shadow: 0 6px 24px rgba(0,0,0,0.08);
    padding: 20px 32px 30px 32px;
  }
  .talonario-header {
    border-bottom: 2px solid #bbb;
    padding-bottom: 10px;
    margin-bottom: 18px;
    display: flex;
    align-items: center;
    justify-content: space-between;
  }
  .talonario-header img {
    height: 54px;
    margin-right: 18px;
  }
  .talonario-header h2 {
    font-weight: bold;
    color: #222;
    margin: 0;
    font-size: 2.1rem;
    flex: 1;
    text-align: center;
  }
  .talonario-header .sucursal {
    font-size: 1.1rem;
    color: #444;
    font-weight: 600;
  }
  .section-title {
    color: #1565c0;
    margin-top: 18px;
    font-size: 17px;
    font-weight: bold;
    margin-bottom: 5px;
    border-left: 4px solid #1565c0;
    padding-left: 8px;
    letter-spacing: 0.5px;
    display: flex;
    align-items: center;
  }
  .add-btn, .remove-btn {
    background: #1565c0;
    color: white;
    border: none;
    border-radius: 6px;
    padding: 3px 13px;
    cursor: pointer;
    font-size: 13px;
    margin-left: 10px;
    transition: background 0.2s;
    outline: none;
  }
  .add-btn:hover, .remove-btn:hover {
    background: #0d47a1;
  }
  .add-btn.yellow {
    background: #f8c000;
    color: #222;
  }
  table {
    width: 100%;
    border-collapse: collapse;
    background: #f8fafd;
    margin-bottom: 14px;
    border-radius: 4px;
    overflow: hidden;
    box-shadow: 0 2px 4px rgba(0,0,0,0.03);
  }
  th, td {
    border: 1px solid #d1d1d1;
    padding: 7px 6px;
    font-size: 13px;
    text-align: center;
  }
  th {
    background: #1565c0;
    color: #fff;
    font-weight: 600;
  }
  .info-group {
    margin-bottom: 6px;
    display: flex;
    align-items: center;
    gap: 10px;
  }
  .info-group label {
    font-weight: bold;
    color: #222;
    min-width: 180px;
    text-align: left;
    display: inline-block;
  }
  .info-group span {
    font-size: 15px;
    color: #222;
    font-weight: normal;
    display: inline-block;
    min-width: 80px;
  }
  .info-group input[type="text"] {
    background: #eee;
    color: #000;
    border: 1px solid #bbb;
    border-radius: 8px;
    padding: 8px 12px;
    font-size: 15px;
    width: 220px;
    margin-left: 4px;
    transition: 0.2s;
  }
  .info-group input[type="text"]:focus {
    border-color: #1565c0;
    background: #fff;
  }
  .comp-form-group {
    display: flex;
    flex-direction: column;
    align-items: stretch;
    width: 100%;
  }
  .comp-form-group label {
    margin-bottom: 6px;
    font-weight: 600;
    font-size: 14px;
    color: #555;
    text-align: left;
  }
  .comp-form-group input[type=text],
  .comp-form-group input[type=number],
  .comp-form-group input[type=date],
  .comp-form-group input[type=email],
  .comp-form-group input[type=password],
  .comp-form-group select {
    display: block !important;
    visibility: visible !important;
    opacity: 1 !important;
    background-color: #fff !important;
    color: #000 !important;
    padding: 10px 12px !important;
    border: 1px solid #ccc !important;
    border-radius: 8px !important;
    outline: none;
    transition: 0.2s;
    font-size: 15px;
    width: 100%;
    box-sizing: border-box;
  }
  .comp-form-group input:focus,
  .comp-form-group select:focus {
    border-color: #388E3C;
    box-shadow: 0 0 5px rgba(56,142,60,0.3);
  }
  .comp-form-group textarea {
    padding: 10px 12px;
    border: 1px solid #ccc;
    border-radius: 8px;
    outline: none;
    resize: vertical;
    min-height: 100px;
    font-family: inherit;
    transition: 0.2s;
    background-color: #fff;
    color: #000;
    font-size: 15px;
    width: 100%;
    box-sizing: border-box;
  }
  .comp-form-group textarea:focus {
    border-color: #388E3C;
    box-shadow: 0 0 5px rgba(56,142,60,0.3);
  }
  .comp-form-group input[type=checkbox],
  .comp-form-group input[type=radio] {
    width: auto;
    margin-right: 6px;
    transform: scale(1.2);
    cursor: pointer;
  }
  .comp-btn {
    width: 100%;
    padding: 14px;
    background: #388E3C;
    color: white;
    border: none;
    border-radius: 10px;
    font-size: 16px;
    font-weight: bold;
    cursor: pointer;
    transition: 0.3s;
  }
  @media (max-width: 900px) {
    .talonario-container {padding: 12px 6px;}
    .talonario-header h2 {font-size: 1.3rem;}
    .info-group label {min-width: 120px;}
    .info-group input[type="text"] {width:100%;}
  }
  table input[type=checkbox] {
    display: inline-block !important;
    visibility: visible !important;
    opacity: 1 !important;
    width: 18px;
    height: 18px;
    cursor: pointer;
    accent-color: #1565c0;
  }
  table input[type=text],
  table input[type=number],
  table input[type=checkbox],
  table select {
    display: inline-block !important;
    visibility: visible !important;
    opacity: 1 !important;
    background: #fff;
    color: #000;
    border: 1px solid #ccc;
    border-radius: 4px;
    padding: 4px 6px;
    font-size: 13px;
    box-sizing: border-box;
  }
  table.tueste-table {
    width: 100%;
    border-collapse: collapse;
    background: #f8fafd;
    margin-bottom: 14px;
    border-radius: 4px;
    overflow: hidden;
    box-shadow: 0 2px 4px rgba(0,0,0,0.03);
  }
  table.tueste-table th, 
  table.tueste-table td {
    border: 1px solid #d1d1d1;
    padding: 7px 2px;
    font-size: 13px;
    text-align: center;
  }
  table.tueste-table th {
    background: #1565c0;
    color: #fff;
    font-weight: 600;
  }
  #peso_neto_restante {
    color: #1565c0;
    font-weight: bold;
    background: #e3f2fd;
  }
  .sacos-table thead th { background:#0b63a1; color:#fff; }
  .sacos-table td { font-size:13px; padding:8px; }
  .sacos-editable { background:#fffbe6; }
</style>
<script>
  // JS para tueste dinámico y validación LV/peso neto (sin cambios importantes)
  function actualizarPesoNetoRestante() {
    var pesoNetoEl = document.getElementById('peso_neto');
    if (!pesoNetoEl) return;
    var pesoNeto = parseFloat(pesoNetoEl.value) || 0;
    var lvInputs = document.querySelectorAll('input[name^="tueste"][name$="[lv]"]');
    var totalLV = 0;
    lvInputs.forEach(function(input) {
      var val = parseFloat(input.value) || 0;
      totalLV += val;
    });
    var restante = pesoNeto - totalLV;
    var el = document.getElementById('peso_neto_restante');
    if (el) el.value = restante.toFixed(2);
  }

  function validarLV(e) {
    var pesoNeto = parseFloat(document.getElementById('peso_neto').value) || 0;
    var lvInputs = document.querySelectorAll('input[name^="tueste"][name$="[lv]"]');
    var totalLV = 0;
    lvInputs.forEach(function(input){
      if (input !== e.target) {
        totalLV += parseFloat(input.value) || 0;
      }
    });
    var valorActual = parseFloat(e.target.value) || 0;
    if (valorActual > pesoNeto) {
      alert('No puedes ingresar en LV más del peso neto disponible.');
      e.target.value = '';
      actualizarPesoNetoRestante();
      return;
    }
    if (totalLV + valorActual > pesoNeto) {
      alert('La suma de LV no puede ser mayor al peso neto disponible.');
      e.target.value = '';
      actualizarPesoNetoRestante();
      return;
    }
    actualizarPesoNetoRestante();
  }

  function addTuesteRow() {
    var soloLectura = <?php echo $solo_lectura ? 'true' : 'false'; ?>;
    if (soloLectura) {
      alert('Esta orden está en modo solo lectura (completada). No puede editarse.');
      return;
    }
    var table = document.getElementById('tueste-body');
    if (!table) return;
    let idx = table.rows.length;
    let row = table.insertRow();
    row.innerHTML = `
      <td><span class="bach-num">${idx+1}</span>
        <input type="hidden" name="tueste[${idx}][bach]" value="${idx+1}">
      </td>
      <td><input type="text" name="tueste[${idx}][lv]" style="width:40px"></td>
      <td><input type="text" name="tueste[${idx}][lt]" style="width:40px"></td>
      <td><input type="text" name="tueste[${idx}][ti]" style="width:40px"></td>
      <td><input type="text" name="tueste[${idx}][pc]" style="width:40px"></td>
      <td><input type="number" name="tueste[${idx}][minutos]" min="0" style="width:45px"></td>
      <td><input type="text" name="tueste[${idx}][tk]" style="width:40px"></td>
      <td><input type="text" name="tueste[${idx}][tf]" style="width:40px"></td>
      <td><input type="text" name="tueste[${idx}][tt]" style="width:40px"></td>
      <td><input type="text" name="tueste[${idx}][rt]" style="width:40px" readonly></td>
      <td><button type="button" class="remove-btn" onclick="removeRow(this)">X</button></td>
    `;
    const lvInput = row.querySelector('input[name^="tueste"][name$="[lv]"]');
    const ltInput = row.querySelector('input[name^="tueste"][name$="[lt]"]');
    const rtInput = row.querySelector('input[name^="tueste"][name$="[rt]"]');
    function updateRT() {
      let lv = parseFloat(lvInput.value);
      let lt = parseFloat(ltInput.value);
      if (!isNaN(lv) && !isNaN(lt) && lt !== 0) {
        rtInput.value = (lv / lt).toFixed(2);
      } else {
        rtInput.value = '';
      }
      actualizarPesoNetoRestante();
    }
    lvInput.addEventListener('input', updateRT);
    ltInput.addEventListener('input', updateRT);
    lvInput.addEventListener('input', validarLV);
  }

  function removeRow(btn) {
    btn.closest('tr').remove();
    actualizarPesoNetoRestante();
  }

  // Actualizar suma dinámica cuando se edita un peso de saco
  function actualizarTotalSacosEnCliente() {
    var inputs = document.querySelectorAll('.saco-peso-input');
    var total = 0;
    inputs.forEach(function(inp) {
      var v = parseFloat(inp.value) || 0;
      total += v;
    });
    var el = document.getElementById('peso_neto');
    if (el) el.value = total.toFixed(3);
    actualizarPesoNetoRestante();
  }

  window.addEventListener('load', function() {
    var tuesteBody = document.getElementById('tueste-body');
    var hasRows = tuesteBody && tuesteBody.querySelectorAll('tr').length > 0;
    var allowAutoAdd = <?php echo $solo_lectura ? 'false' : 'true'; ?>;
    if (!hasRows && allowAutoAdd) {
      addTuesteRow();
    }
    actualizarPesoNetoRestante();

    // attach change listeners to saco peso inputs (if any editable)
    document.querySelectorAll('.saco-peso-input').forEach(function(inp) {
      inp.addEventListener('input', function() {
        // quick numeric validation
        if (this.value !== '' && isNaN(this.value)) {
          this.classList.add('input-error');
        } else {
          this.classList.remove('input-error');
        }
        actualizarTotalSacosEnCliente();
      });
    });
  });
</script>
</head>
<body>
  <div class="talonario-container">
    <div class="talonario-header">
      <img src="img/logo.png" alt="Logo Estrucafé">
      <h2>Orden de Trabajo Estucafé</h2>
      <span class="sucursal">SUCURSAL: <?php echo htmlspecialchars($sucursal_usuario); ?></span>
    </div>

    <?php if (!empty($mensajes)): ?>
      <div style="background:#e8f6e8;border:1px solid #2e7d32;color:#145a32;padding:12px;margin-bottom:14px;border-radius:8px;">
        <?php foreach ($mensajes as $m): ?>
          <div><?php echo htmlspecialchars($m); ?></div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <?php if (!empty($errores)): ?>
      <div style="background:#ffdddd;border:1px solid #b71c1c;color:#b71c1c;padding:12px;margin-bottom:14px;border-radius:8px;">
        <?php foreach ($errores as $error): ?>
          <div><?php echo htmlspecialchars($error); ?></div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <form method="POST">
      <div class="talonario-info-flex">
  <!-- Columna izquierda: datos principales -->
  <div class="info-col">
    <div class="info-group">
      <label>ORDEN DE TRABAJO Nº:</label>
      <span><?php echo isset($orden['numero_ingreso']) ? $orden['numero_ingreso'] : ''; ?></span>
    </div>
    <div class="info-group">
      <label>FECHA:</label>
      <span><?php echo isset($orden['fecha']) ? date('d/m/Y', strtotime($orden['fecha'])) : ''; ?></span>
    </div>
    <div class="info-group">
      <label>Nombre del cliente:</label>
      <span><?php echo isset($orden['nombre_cliente']) ? $orden['nombre_cliente'] : ''; ?></span>
    </div>
    <div class="info-group">
      <label>Tipo de café:</label>
      <span><?php echo isset($orden['variedad']) ? $orden['variedad'] : ''; ?></span>
    </div>
    <div class="info-group">
      <label>Empleado que ejecuta:</label>
      <span><?php echo htmlspecialchars($nombre_empleado); ?></span>
    </div>
  </div>
  <!-- Columna derecha: Inputs editables -->
   <!-- Campos con estilo Bootstrap mejorado -->
<div class="form-row mt-3 mb-2 input-col">
  <div class="form-group">
    <label for="nombreEmpleado">
      <i class="fas fa-user-tie mr-1"></i>
      Empleado que procesa el café
    </label>
    <input type="text" class="form-control" id="nombreEmpleado" name="nombre_empleado"
      value="<?php echo isset($_POST['nombre_empleado']) ? htmlspecialchars($_POST['nombre_empleado']) : htmlspecialchars($nombre_empleado); ?>"
      placeholder="Nombre del empleado" required>
  </div>

  <div class="form-group">
    <label for="codigoEmpleado">
      <i class="fas fa-id-card mr-1"></i>
      Código empleado
    </label>
    <input type="text" class="form-control" id="codigoEmpleado" name="codigo_empleado"
      value="<?php echo isset($_POST['codigo_empleado']) ? htmlspecialchars($_POST['codigo_empleado']) : htmlspecialchars($codigo_empleado); ?>"
      placeholder="Código del empleado" required>
  </div>
</div>

</div>

      <div class="section-title" style="display: flex; align-items: center; gap: 8px;">
        DETALLE DEL PEDIDO
        <button type="submit" name="guardar_edicion_detalle" class="add-btn blue" style="margin-left:12px;">
          Guardar Edición 
        </button>
      </div>

      <div style="overflow-x:auto;">
        <table>
          <thead>
            <tr>
              <th>Cantidad</th>
              <th>Unidades</th>
              <th>Empaque/Color</th>
              <th>Granel</th>
              <th>Vena</th>
              <th>Molido</th>
              <th>Grano</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($detalle_pedido_db as $idx => $detalle): ?>
            <tr>
              <td>
                <input 
                  type="number"
                  step="0.01"
                  inputmode="decimal"
                  name="detalle_pedido[<?php echo $idx; ?>][cantidad]"
                  value="<?php echo number_format((float)$detalle['cantidad'], 2, '.', ''); ?>"
                  required
                >
              </td>
              <td>
                <input type="text" name="detalle_pedido[<?php echo $idx; ?>][unidad]" value="<?php echo htmlspecialchars($detalle['unidad']); ?>" required>
              </td>
              <td>
                <select name="detalle_pedido[<?php echo $idx; ?>][codigo_empaque]">
                  <?php foreach($empaques as $codigo => $nombre): ?>
                    <option value="<?php echo htmlspecialchars($codigo); ?>"
                      <?php echo ($detalle['codigo_empaque'] == $codigo ? 'selected' : ''); ?>>
                      <?php echo htmlspecialchars($nombre); ?>
                    </option>
                  <?php endforeach; ?>
                  <?php if (!isset($empaques[$detalle['codigo_empaque']])): ?>
                    <option value="<?php echo htmlspecialchars($detalle['codigo_empaque']); ?>" selected>
                      <?php echo htmlspecialchars($detalle['codigo_empaque']); ?>
                    </option>
                  <?php endif; ?>
                </select>
              </td>
              <td>
                <input type="checkbox" name="detalle_pedido[<?php echo $idx; ?>][granel]" <?php echo ($detalle['granel'] ? 'checked' : ''); ?> disabled>
              </td>
              <td>
                <input type="checkbox" name="detalle_pedido[<?php echo $idx; ?>][vena]" <?php echo ($detalle['vena'] ? 'checked' : ''); ?> disabled>
              </td>
              <td>
                <input type="checkbox" name="detalle_pedido[<?php echo $idx; ?>][molido]" <?php echo ($detalle['molido'] ? 'checked' : ''); ?> disabled>
              </td>
              <td>
                <input type="checkbox" name="detalle_pedido[<?php echo $idx; ?>][grano]" <?php echo ($detalle['grano'] ? 'checked' : ''); ?> disabled>
              </td>
              <input type="hidden" name="detalle_pedido[<?php echo $idx; ?>][id]" value="<?php echo htmlspecialchars($detalle['id']); ?>">
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <!-- NUEVA SECCIÓN: TABLA DE SACOS (solo lectura excepto peso neto para ciertos estados) -->
      <div class="section-title" style="margin-top:18px;">SACOS (Ingreso_sacos)</div>

      <div style="overflow-x:auto;">
      
      <table border="1" width="100%" cellpadding="6" cellspacing="0">
        <thead>
          <tr>
            <th>No Saco</th><th>Variedad</th><th>Altura</th><th>Humedad</th><th>Estado</th><th>Peso bruto</th><th>Tara</th><th>Peso neto</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($sacos as $i => $saco):
            $estado_lower = strtolower(trim($saco['Estado_cafe'] ?? ''));
            $editable = in_array($estado_lower, ['pergamino', 'pergamino seco', 'cereza']);
          ?>
            <tr class="<?php echo $editable ? 'sacos-editable' : ''; ?>">
              <td><?php echo htmlspecialchars($saco['No_Saco']); ?></td>
              <td><?php echo htmlspecialchars($saco['Variedad']); ?></td>
              <td><?php echo htmlspecialchars($saco['Altura']); ?></td>
              <td><?php echo htmlspecialchars($saco['Humedad']); ?></td>
              <td><?php echo htmlspecialchars($saco['Estado_cafe']); ?></td>
              <td><?php echo number_format($saco['peso_bruto'],3,'.',''); ?></td>
              <td><?php echo number_format($saco['tara'],3,'.',''); ?></td>
              <td>
                <input
                  type="text"
                  name="sacos[<?php echo $i;?>][peso_neto_cafe]"
                  class="saco-peso-input"
                  value="<?php echo $saco['peso_neto_cafe'] !== null ? number_format($saco['peso_neto_cafe'],3,'.','') : ''; ?>"
                  <?php echo $editable ? '' : 'readonly'; ?>
                  style="<?php echo $editable ? '' : 'background:#f3f7fb;cursor:not-allowed;'; ?> width:110px">
                <input type="hidden" name="sacos[<?php echo $i;?>][id]" value="<?php echo intval($saco['id']); ?>">
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>

      <?php if (!$solo_lectura): ?>
        <p><small>Nota: solo pueden editarse pesos netos en sacos con estado "Pergamino" o "Cereza". Deja vacío para guardar NULL.</small></p>
        <button type="submit" name="guardar_pesos_sacos">Guardar Pesos de Sacos</button>
      <?php endif; ?>
      </div>

      <?php if (!$solo_lectura): ?>
        <div style="display:flex;gap:12px;margin-bottom:12px;">
          <button type="submit" name="guardar_pesos_sacos" class="add-btn yellow">Guardar Pesos de Sacos</button>
          <small style="align-self:center;color:#555">Solo se pueden editar pesos netos de sacos cuyo Estado sea "Pergamino" o "Cereza".</small>
        </div>
      <?php endif; ?>

      <div class="comp-form-group" style="margin-bottom:14px;">
        <label for="peso_neto" style="font-weight:bold;">Peso Neto total (lb) — suma de sacos:</label>
        <input type="text" id="peso_neto" name="peso_neto" value="<?php echo htmlspecialchars(number_format($totalPesoSacos, 3, '.', '')); ?>" readonly style="background:#eee;width:160px;">
      </div>

<div class="comp-form-group" style="margin-bottom:14px;">
  <label for="peso_neto_restante" style="font-weight:bold;color:#1565c0;">Peso Neto Restante (lb):</label>
  <input type="text" id="peso_neto_restante" value="<?php echo isset($orden['peso_neto']) ? htmlspecialchars($orden['peso_neto']) : ''; ?>" readonly style="background:#e3f2fd;cursor:not-allowed;width:160px;color:#1565c0;font-weight:bold;">
</div>

      <!-- DETALLE TUESTE: si es solo_lectura mostramos $detalle_tueste_all (histórico),
     si no es solo_lectura mostramos $detalle_tueste_db (en pausa) y podemos agregar filas -->
<div class="section-title">DETALLE DE TUESTE
  <?php if (!$solo_lectura): ?>
    <button type="button" class="add-btn" onclick="addTuesteRow()">Agregar fila tueste</button>
  <?php endif; ?>
</div>

<div style="overflow-x:auto;">
  <table class="tueste-table">
    <thead>
      <tr>
        <th>BACH</th>
        <th>LV</th>
        <th>LT</th>
        <th>TI</th>
        <th>PC</th>
        <th>Minutos</th>
        <th>TK</th>
        <th>TF</th>
        <th>TT</th>
        <th>RT</th>
        <th>Quitar</th>
      </tr>
    </thead>
    <tbody id="tueste-body">
      <?php
      // Elegir qué conjunto mostrar
      $tuestes_para_mostrar = $solo_lectura ? $detalle_tueste_all : $detalle_tueste_db;
      foreach ($tuestes_para_mostrar as $idx => $tueste):
      ?>
        <tr>
          <td>
            <span class="bach-num"><?php echo htmlspecialchars($tueste['bach']); ?></span>
            <input type="hidden" name="tueste[<?php echo $idx; ?>][bach]" value="<?php echo htmlspecialchars($tueste['bach']); ?>">
          </td>
          <td><input type="text" name="tueste[<?php echo $idx; ?>][lv]" style="width:40px" value="<?php echo htmlspecialchars($tueste['lv']); ?>" <?php echo $solo_lectura ? 'readonly' : ''; ?>></td>
          <td><input type="text" name="tueste[<?php echo $idx; ?>][lt]" style="width:40px" value="<?php echo htmlspecialchars($tueste['lt']); ?>" <?php echo $solo_lectura ? 'readonly' : ''; ?>></td>
          <td><input type="text" name="tueste[<?php echo $idx; ?>][ti]" style="width:40px" value="<?php echo htmlspecialchars($tueste['ti']); ?>" <?php echo $solo_lectura ? 'readonly' : ''; ?>></td>
          <td><input type="text" name="tueste[<?php echo $idx; ?>][pc]" style="width:40px" value="<?php echo htmlspecialchars($tueste['pc']); ?>" <?php echo $solo_lectura ? 'readonly' : ''; ?>></td>
          <td><input type="number" name="tueste[<?php echo $idx; ?>][minutos]" min="0" style="width:45px" value="<?php echo htmlspecialchars($tueste['minutos']); ?>" <?php echo $solo_lectura ? 'readonly' : ''; ?>></td>
          <td><input type="text" name="tueste[<?php echo $idx; ?>][tk]" style="width:40px" value="<?php echo htmlspecialchars($tueste['tk']); ?>" <?php echo $solo_lectura ? 'readonly' : ''; ?>></td>
          <td><input type="text" name="tueste[<?php echo $idx; ?>][tf]" style="width:40px" value="<?php echo htmlspecialchars($tueste['tf']); ?>" <?php echo $solo_lectura ? 'readonly' : ''; ?>></td>
          <td><input type="text" name="tueste[<?php echo $idx; ?>][tt]" style="width:40px" value="<?php echo htmlspecialchars($tueste['tt']); ?>" <?php echo $solo_lectura ? 'readonly' : ''; ?>></td>
          <td><input type="text" name="tueste[<?php echo $idx; ?>][rt]" style="width:40px" value="<?php echo htmlspecialchars($tueste['rt']); ?>" readonly></td>
          <?php if (!$solo_lectura): ?>
            <td><button type="button" class="remove-btn" onclick="removeRow(this)">X</button></td>
          <?php endif; ?>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

      <div class="comp-form-group observaciones">
        <label>Observaciones/Otras</label>
        <textarea name="observaciones"><?php echo htmlspecialchars($condiciones['observaciones']); ?></textarea>
      </div>
      <div class="section-title" style="margin-top:18px;">Condiciones Ambientales</div>
      <div style="display:flex; gap:32px; margin-bottom:14px; align-items:center;">
        <div class="comp-form-group" style="flex:1;">
          <label for="humedad_cafe" style="white-space:nowrap;">Humedad café (%)</label>
          <input type="number" step="0.01" min="0" max="100" name="humedad_cafe" id="humedad_cafe" placeholder="Ej: 12.5" style="width:120px;" value="<?php echo htmlspecialchars($condiciones['humedad_cafe']); ?>">
        </div>
        <div class="comp-form-group" style="flex:1;">
          <label for="humedad_relativa" style="white-space:nowrap;">Humedad relativa (%)</label>
          <input type="number" step="0.01" min="0" max="100" name="humedad_relativa" id="humedad_relativa" placeholder="Ej: 65.0" style="width:120px;" value="<?php echo htmlspecialchars($condiciones['humedad_relativa']); ?>">
        </div>
        <div class="comp-form-group" style="flex:1;">
          <label for="temperatura_ambiente" style="white-space:nowrap;">Temperatura ambiente (°C)</label>
          <input type="number" step="0.1" min="0" max="60" name="temperatura_ambiente" id="temperatura_ambiente" placeholder="Ej: 22.5" style="width:120px;" value="<?php echo htmlspecialchars($condiciones['temperatura_ambiente']); ?>">
        </div>
      </div>

      <button type="button" id="link-btn" class="comp-btn" style="flex:1;background:#1565c0">Copiar y Generar Link</button>
       <?php if (!$solo_lectura): ?>
  <button type="submit" name="guardar_avance" class="comp-btn" style="margin-top:18px;background:#1565c0;">Guardar Avance</button>
      <button type="submit" name="guardar_orden" class="comp-btn" style="margin-top:18px;">Guardar Orden</button>
      <br>
        
  <?php endif; ?>
  
    </form>
  </div>
</body>
</html>
<?php
$conexion->close();
?>