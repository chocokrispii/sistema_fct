---
title: Diagrama completo de arquitectura
description: Vista amplia de los principales recorridos de datos en Sistema FCT.
wide: true
aside: false
---

# Diagrama completo de arquitectura

Vista de referencia de los límites del sistema y sus recorridos principales. Cada subsistema conserva su propio diagrama para que los detalles no reduzcan la legibilidad del mapa general.

## Límites del sistema

```mermaid
flowchart TB
    client["Cliente<br/>Vue 3 + formularios HTML"]
    gateway["Pasarela HTTP<br/>rutas, sesión, roles y CSRF"]
    mvc["Núcleo PHP MVC<br/>controladores y modelos"]
    database[("MariaDB")]
    files["Documentos privados<br/>FileScanner + ClamAV"]

    client -->|"HTTP / JSON / formularios"| gateway
    gateway -->|"petición validada"| mvc
    mvc -->|"SQL / transacciones"| database
    mvc -->|"flujo de archivo"| files

    classDef frontend fill:#e8f3ff,stroke:#2878b5,color:#123047,stroke-width:2px;
    classDef gateway fill:#fff9df,stroke:#9b7b00,color:#473800,stroke-width:2px;
    classDef backend fill:#f1edff,stroke:#7254a8,color:#2e2148,stroke-width:2px;
    classDef database fill:#e8f7ee,stroke:#29804b,color:#183d27,stroke-width:2px;
    classDef security fill:#fff1e6,stroke:#c45b16,color:#54280d,stroke-width:2px;

    class client frontend;
    class gateway gateway;
    class mvc backend;
    class database database;
    class files security;
```

## Autenticación y sesión

```mermaid
flowchart TB
    pinia["Pinia<br/>session store"]
    api["api/client.ts<br/>fetch same-origin"]
    entry["public/index.php<br/>sesión y rutas"]
    auth["AuthController<br/>login / session / logout"]
    json["JsonResponse<br/>{ success, data } o { success, error }"]
    db[("MariaDB<br/>Usuario e intentos")]

    pinia -->|"GET session / POST login o logout"| api
    api -->|"JSON + Cookie; X-CSRF-Token en POST"| entry
    entry -->|"CSRF y ruta validada"| auth
    auth -->|"consulta y actualiza"| db
    db -->|"usuario y rol"| auth
    auth --> json
    json -->|"respuesta JSON"| api
    api -->|"estado de sesión"| pinia

    classDef frontend fill:#e8f3ff,stroke:#2878b5,color:#123047,stroke-width:2px;
    classDef gateway fill:#fff9df,stroke:#9b7b00,color:#473800,stroke-width:2px;
    classDef backend fill:#f1edff,stroke:#7254a8,color:#2e2148,stroke-width:2px;
    classDef database fill:#e8f7ee,stroke:#29804b,color:#183d27,stroke-width:2px;
    class pinia,api frontend;
    class entry gateway;
    class auth,json backend;
    class db database;
```

## Ciclo de vida de un convenio PDF

```mermaid
flowchart TB
    form["Formulario PHP<br/>multipart + CSRF"]
    route["Ruta y permisos<br/>PHP MVC"]
    controller["ConvenioController<br/>validación y metadatos"]
    scanner["FileScanner<br/>tipo y tamaño"]
    clamav["ClamAV / clamd<br/>INSTREAM por TCP"]
    storage[("Almacenamiento privado<br/>FCT_STORAGE_PATH")]
    db[("MariaDB<br/>metadatos y ruta opaca")]

    form -->|"archivo temporal"| route
    route -->|"petición autorizada"| controller
    controller -->|"archivo validado"| scanner
    scanner -->|"flujo de archivo"| clamav
    clamav -->|"limpio / infectado / error"| scanner
    scanner -->|"solo si está limpio"| storage
    controller -->|"metadatos del documento"| db
    storage -->|"descarga protegida"| controller

    classDef frontend fill:#e8f3ff,stroke:#2878b5,color:#123047,stroke-width:2px;
    classDef gateway fill:#fff9df,stroke:#9b7b00,color:#473800,stroke-width:2px;
    classDef backend fill:#f1edff,stroke:#7254a8,color:#2e2148,stroke-width:2px;
    classDef security fill:#fff1e6,stroke:#c45b16,color:#54280d,stroke-width:2px;
    classDef database fill:#e8f7ee,stroke:#29804b,color:#183d27,stroke-width:2px;
    class form frontend;
    class route gateway;
    class controller backend;
    class scanner,clamav security;
    class storage,db database;
```

## Petición MVC tradicional

```mermaid
flowchart TB
    browser["Navegador<br/>HTML y cookies"]
    router["public/index.php<br/>routes/web.php"]
    middleware["Middleware<br/>método, sesión, rol y CSRF"]
    controller["Controlador<br/>validación y reglas"]
    model["Modelo"]
    pdo["PDO<br/>consultas preparadas"]
    db[("MariaDB")]
    view["Vista PHP<br/>HTML + islas Vue"]

    browser -->|"GET / POST"| router
    router -->|"acción"| middleware
    middleware -->|"petición permitida"| controller
    controller --> model
    model --> pdo
    pdo -->|"SQL"| db
    db -->|"ResultSet"| pdo
    pdo --> model
    model --> controller
    controller --> view
    view -->|"HTML / redirección"| browser

    classDef frontend fill:#e8f3ff,stroke:#2878b5,color:#123047,stroke-width:2px;
    classDef gateway fill:#fff9df,stroke:#9b7b00,color:#473800,stroke-width:2px;
    classDef backend fill:#f1edff,stroke:#7254a8,color:#2e2148,stroke-width:2px;
    classDef database fill:#e8f7ee,stroke:#29804b,color:#183d27,stroke-width:2px;
    class browser frontend;
    class router,middleware gateway;
    class controller,model,view backend;
    class pdo,db database;
```

<p><a href="/flujo-de-datos">Volver al documento de arquitectura y ciclo de vida</a></p>
