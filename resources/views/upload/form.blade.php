<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>CSV Upload</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<nav class="navbar navbar-expand-lg bg-body-tertiary">
    <div class="container">
        <a class="navbar-brand" href="#">Urkunden Generator</a>
        <div class="ms-auto">
            <form method="post" action="{{ route('logout') }}">
                @csrf
                <button class="btn btn-outline-secondary btn-sm" type="submit">Logout</button>
            </form>
        </div>
    </div>
</nav>
<div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-md-8">
            <div class="card shadow-sm">
                <div class="card-body">
                    <h1 class="h4 mb-3">CSV hochladen und PDF erzeugen</h1>
                    <p class="text-muted">Erwartete Spalten: <strong>Vorname</strong>, <strong>Name</strong>, <strong>Verein</strong>, <strong>Altersklasse</strong>, <strong>Punkte</strong>, <strong>Platz</strong>. Eine erste Kopfzeile ist optional. Ohne Kopfzeile muss die Spaltenreihenfolge genau wie hier angegeben sein.</p>
                    <p class="text-muted">Beim Excel Export darauf achten, dass es im Format .csv gespeichert wird. Wichtig ist die CSV UTF-8 Variante zu nehmen.</p>
                    @if ($errors->any())
                        <div class="alert alert-danger">
                            <ul class="mb-0">
                                @foreach ($errors->all() as $error)
                                    <li>{{ $error }}</li>
                                @endforeach
                            </ul>
                        </div>
                    @endif

                    <form method="post" action="{{ route('upload.process') }}" enctype="multipart/form-data">
                        @csrf
                        <div class="mb-3">
                            <label for="file" class="form-label">CSV-Datei</label>
                            <input class="form-control @error('file') is-invalid @enderror" type="file" id="file" name="file" accept=".csv" required>
                            @error('file')
                            <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>
                        <div class="d-grid gap-2">
                            <button class="btn btn-primary" type="submit">PDF erzeugen</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>
</body>
</html>