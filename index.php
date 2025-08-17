<?php
// STEM Results Portal - Improved Version for Google Sheets Upload
error_reporting(E_ALL);
ini_set('display_errors', 1);
header('Content-Type: text/html; charset=utf-8');

class STEMResultsPortal {
    private $csv_data = null;
    private $csv_file_path = 'results.csv';
    
    public function __construct() {
        $this->loadCSVData();
    }
    
    private function loadCSVData() {
        if (file_exists($this->csv_file_path)) {
            $this->loadCSVFromFile($this->csv_file_path);
        }
    }
    
    private function loadCSVFromFile($file_path) {
        try {
            if (file_exists($file_path)) {
                $content = file_get_contents($file_path);
                $this->processCSVContent($content);
                return true;
            }
        } catch (Exception $e) {
            error_log("Error loading CSV: " . $e->getMessage());
            $this->csv_data = [];
            return false;
        }
        return false;
    }
    
    private function processCSVContent($content) {
        if (empty(trim($content))) {
            throw new Exception("CSV file is empty");
        }
        
        // Remove BOM if present
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content);
        
        // Try different encodings and delimiters
        $encodings = ['UTF-8', 'ISO-8859-1', 'Windows-1252', 'Windows-1256'];
        $delimiters = [',', ';', "\t"];
        
        foreach ($encodings as $encoding) {
            foreach ($delimiters as $delimiter) {
                try {
                    $processed_content = $content;
                    if ($encoding !== 'UTF-8' && function_exists('mb_convert_encoding')) {
                        $processed_content = mb_convert_encoding($content, 'UTF-8', $encoding);
                    }
                    
                    $lines = explode("\n", trim($processed_content));
                    if (count($lines) < 2) continue;
                    
                    // Parse header
                    $headers = str_getcsv(trim($lines[0]), $delimiter);
                    $headers = array_map('trim', $headers);
                    $headers = array_filter($headers, function($h) { return !empty($h); });
                    
                    if (count($headers) < 2) continue;
                    
                    // Parse data rows
                    $this->csv_data = [];
                    $valid_rows = 0;
                    
                    for ($i = 1; $i < count($lines); $i++) {
                        $line = trim($lines[$i]);
                        if (empty($line)) continue;
                        
                        $row = str_getcsv($line, $delimiter);
                        if (count($row) < 2) continue; // Must have at least 2 columns
                        
                        $assoc_row = [];
                        for ($j = 0; $j < count($headers); $j++) {
                            $value = isset($row[$j]) ? trim($row[$j]) : '';
                            $assoc_row[$headers[$j]] = $value;
                        }
                        
                        // Only add rows that have meaningful data
                        if (!empty($assoc_row[$headers[0]])) {
                            $this->csv_data[] = $assoc_row;
                            $valid_rows++;
                        }
                    }
                    
                    if ($valid_rows > 0) {
                        return;
                    }
                } catch (Exception $e) {
                    continue;
                }
            }
        }
        
        throw new Exception("Could not process CSV with any encoding or delimiter combination");
    }
    
    public function uploadCSVFile($uploaded_file) {
        try {
            if (!isset($uploaded_file['tmp_name']) || !is_uploaded_file($uploaded_file['tmp_name'])) {
                throw new Exception("No valid file uploaded");
            }
            
            // Validate file type
            $file_info = pathinfo($uploaded_file['name']);
            $allowed_extensions = ['csv', 'txt'];
            
            if (!isset($file_info['extension']) || !in_array(strtolower($file_info['extension']), $allowed_extensions)) {
                throw new Exception("Only CSV and TXT files are allowed");
            }
            
            // Read file content
            $file_content = file_get_contents($uploaded_file['tmp_name']);
            if ($file_content === false) {
                throw new Exception("Could not read uploaded file");
            }
            
            // Save as results.csv in the same directory
            if (file_put_contents($this->csv_file_path, $file_content) === false) {
                throw new Exception("Could not save CSV file");
            }
            
            // Process the new file
            $this->processCSVContent($file_content);
            
            return [
                'success' => true,
                'message' => 'CSV file uploaded and processed successfully',
                'filename' => 'results.csv',
                'rows' => count($this->csv_data),
                'columns' => !empty($this->csv_data) ? array_keys($this->csv_data[0]) : []
            ];
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    // Remove the cleanOldFiles method since we're only keeping one file
    
    private function findColumn($keywords) {
        if (empty($this->csv_data)) return null;
        
        $headers = array_keys($this->csv_data[0]);
        
        foreach ($headers as $col) {
            $col_lower = strtolower($col);
            foreach ($keywords as $keyword) {
                if (strpos($col_lower, $keyword) !== false) {
                    return $col;
                }
            }
        }
        
        return $headers[0] ?? null;
    }
    
    private function cleanText($text) {
        return trim(preg_replace('/[^\p{L}\p{N}\p{P}\p{Z}]/u', '', $text));
    }
    
    private function isPassingScore($score) {
        try {
            $score_num = floatval(str_replace(['%', ','], '', $score));
            return $score_num >= 250;
        } catch (Exception $e) {
            return false;
        }
    }
    
    public function searchStudent($seat_number) {
        try {
            if (empty($this->csv_data)) {
                return ['error' => 'No data available. Please upload a CSV file first.'];
            }
            
            $seat_number = trim($seat_number);
            if (empty($seat_number)) {
                return ['error' => 'Seat number required'];
            }
            
            // Find relevant columns with more flexible matching
            $seat_col = $this->findColumn(['seat', 'id', 'seating', 'number', 'رقم']);
            $name_col = $this->findColumn(['name', 'arabic', 'student', 'اسم', 'الطالب']);
            $score_col = $this->findColumn(['total', 'score', 'degree', 'marks', 'درجة']);
            $status_col = $this->findColumn(['status', 'result', 'pass', 'نتيجة']);
            
            if (!$seat_col) {
                return ['error' => 'Could not find seat number column in the CSV'];
            }
            
            // Search for student (case-insensitive)
            $student_found = null;
            $student_index = null;
            
            foreach ($this->csv_data as $index => $row) {
                $row_seat = strtoupper(trim(strval($row[$seat_col] ?? '')));
                if ($row_seat === strtoupper($seat_number)) {
                    $student_found = $row;
                    $student_index = $index;
                    break;
                }
            }
            
            if ($student_found === null) {
                return ['error' => "Student with seat number '$seat_number' not found"];
            }
            
            // Calculate rank (position + 1)
            $rank = $student_index + 1;
            
            // Get student name
            $student_name = $student_found[$name_col] ?? "Name not available";
            if (!empty($student_name) && !in_array(strtolower($student_name), ["", "n/a", "nan", "none", "null"])) {
                $student_name = $this->cleanText($student_name);
            } else {
                $student_name = "Name not available";
            }
            
            // Get score
            $total_score = $student_found[$score_col] ?? "N/A";
            
            // Get status
            $status = "UNKNOWN";
            if (!empty($student_found[$status_col] ?? '')) {
                $status = strtoupper(trim($student_found[$status_col]));
            } elseif ($total_score !== "N/A" && $this->isPassingScore($total_score)) {
                $status = "PASS";
            } elseif ($total_score !== "N/A") {
                $status = "FAIL";
            }
            
            return [
                'seatNumber' => $student_found[$seat_col] ?? "N/A",
                'seating_no' => $student_found[$seat_col] ?? "N/A",
                'name' => $student_name,
                'arabic_name' => $student_name,
                'totalScore' => $total_score,
                'total' => $total_score,
                'maxScore' => 500,
                'status' => $status,
                'rank' => $rank,
                'index' => $student_index,
                'all_data' => $student_found // Include all data for debugging
            ];
            
        } catch (Exception $e) {
            return ['error' => $e->getMessage()];
        }
    }
    
    public function getStats() {
        $stats = [
            'has_data' => !empty($this->csv_data),
            'total_students' => empty($this->csv_data) ? 0 : count($this->csv_data),
            'csv_file_path' => $this->csv_file_path,
            'file_exists' => file_exists($this->csv_file_path)
        ];
        
        if (!empty($this->csv_data)) {
            $stats['columns'] = array_keys($this->csv_data[0]);
            $stats['sample_data'] = array_slice($this->csv_data, 0, 3);
        }
        
        return $stats;
    }
    
    public function serveHTML() {
        $stats = $this->getStats();
        $has_data = $stats['has_data'];
        
        return '
        <!DOCTYPE html>
        <html>
        <head>
            <title>STEM Results Portal</title>
            <meta charset="utf-8">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <style>
                body { 
                    font-family: Arial, sans-serif; 
                    margin: 0; 
                    padding: 20px; 
                    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                    min-height: 100vh;
                }
                .container { 
                    max-width: 700px; 
                    margin: 0 auto; 
                    background: white;
                    border-radius: 15px;
                    box-shadow: 0 10px 30px rgba(0,0,0,0.2);
                    padding: 30px;
                }
                .header {
                    text-align: center;
                    margin-bottom: 30px;
                }
                .header h1 {
                    color: #333;
                    margin-bottom: 10px;
                    font-size: 28px;
                }
                .status {
                    padding: 15px;
                    border-radius: 8px;
                    margin-bottom: 20px;
                    text-align: center;
                }
                .status.success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
                .status.warning { background: #fff3cd; color: #856404; border: 1px solid #ffeaa7; }
                .status.error { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
                
                .upload-section {
                    border: 2px dashed #ddd;
                    border-radius: 10px;
                    padding: 30px;
                    text-align: center;
                    margin-bottom: 30px;
                    background: #f9f9f9;
                    transition: all 0.3s ease;
                }
                .upload-section.dragover {
                    border-color: #007cba;
                    background: #e3f2fd;
                    transform: scale(1.02);
                }
                
                .search-section {
                    text-align: center;
                    margin-bottom: 20px;
                }
                
                input[type="text"], input[type="file"] {
                    width: 100%;
                    padding: 15px;
                    border: 2px solid #ddd;
                    border-radius: 8px;
                    font-size: 16px;
                    margin-bottom: 15px;
                    box-sizing: border-box;
                }
                input[type="text"]:focus, input[type="file"]:focus {
                    border-color: #007cba;
                    outline: none;
                    box-shadow: 0 0 5px rgba(0,124,186,0.3);
                }
                
                button {
                    background: #007cba;
                    color: white;
                    border: none;
                    padding: 15px 30px;
                    border-radius: 8px;
                    font-size: 16px;
                    cursor: pointer;
                    margin: 5px;
                    transition: all 0.3s ease;
                    font-weight: bold;
                }
                button:hover {
                    background: #005a87;
                    transform: translateY(-2px);
                    box-shadow: 0 4px 8px rgba(0,0,0,0.2);
                }
                button:disabled {
                    background: #ccc;
                    cursor: not-allowed;
                    transform: none;
                    box-shadow: none;
                }
                
                .result {
                    margin-top: 20px;
                    padding: 25px;
                    border-radius: 10px;
                    border: 1px solid #ddd;
                    background: #f8f9fa;
                }
                .result h3 {
                    color: #28a745;
                    margin-top: 0;
                    border-bottom: 2px solid #28a745;
                    padding-bottom: 10px;
                }
                .result .error {
                    color: #dc3545;
                    font-weight: bold;
                }
                .result .info-row {
                    display: flex;
                    justify-content: space-between;
                    padding: 8px 0;
                    border-bottom: 1px solid #eee;
                }
                .result .info-row:last-child {
                    border-bottom: none;
                }
                
                .stats {
                    background: #e3f2fd;
                    padding: 20px;
                    border-radius: 8px;
                    margin-bottom: 20px;
                    font-size: 14px;
                }
                .stats strong {
                    color: #1976d2;
                }
                
                .loading {
                    display: inline-block;
                    width: 20px;
                    height: 20px;
                    border: 3px solid #f3f3f3;
                    border-top: 3px solid #007cba;
                    border-radius: 50%;
                    animation: spin 1s linear infinite;
                    margin-right: 10px;
                }
                
                @keyframes spin {
                    0% { transform: rotate(0deg); }
                    100% { transform: rotate(360deg); }
                }
                
                .hidden { display: none; }
                
                @media (max-width: 600px) {
                    .container { margin: 10px; padding: 20px; }
                    .header h1 { font-size: 24px; }
                    .result .info-row { flex-direction: column; }
                }
            </style>
        </head>
        <body>
            <div class="container">
                <div class="header">
                    <h1>🎓 STEM Results Portal</h1>
                    <p>Upload your Google Sheets CSV and search student results</p>
                </div>
                
                ' . ($has_data ? 
                    '<div class="status success">
                        <strong>✅ Data Loaded Successfully!</strong><br>
                        ' . $stats['total_students'] . ' students available from uploaded file
                    </div>' : 
                    '<div class="status warning">
                        <strong>⚠️ No Data Loaded</strong><br>
                        Please upload your CSV file exported from Google Sheets
                    </div>'
                ) . '
                
                <div class="upload-section" id="uploadSection">
                    <h3>📂 Upload Results CSV</h3>
                    <p>Export your "results" Google Sheet as CSV and upload it here</p>
                    <p><small>File will be saved as "results.csv" in the same directory</small></p>
                    <input type="file" id="csvFile" accept=".csv,.txt" />
                    <button onclick="uploadFile()" id="uploadBtn">📤 Replace results.csv</button>
                    <div id="uploadResult"></div>
                </div>
                
                ' . ($has_data ? '
                <div class="stats">
                    <strong>📊 Current Data:</strong><br>
                    📈 Students: ' . $stats['total_students'] . '<br>
                    📋 Columns: ' . (isset($stats['columns']) ? implode(', ', array_slice($stats['columns'], 0, 4)) . (count($stats['columns']) > 4 ? '...' : '') : 'None') . '<br>
                    📄 File: results.csv ' . ($stats['file_exists'] ? '✅' : '❌') . '
                </div>
                
                <div class="search-section">
                    <h3>🔍 Search Student Results</h3>
                    <input type="text" id="seatNumber" placeholder="Enter seat number (e.g., 12345)" />
                    <button onclick="searchStudent()" id="searchBtn">🔎 Search Student</button>
                </div>
                ' : '') . '
                
                <div id="result"></div>
            </div>
            
            <script>
                // File upload functionality
                const uploadSection = document.getElementById("uploadSection");
                const csvFile = document.getElementById("csvFile");
                
                // Drag and drop handlers
                ["dragenter", "dragover", "dragleave", "drop"].forEach(eventName => {
                    uploadSection.addEventListener(eventName, preventDefaults, false);
                });
                
                ["dragenter", "dragover"].forEach(eventName => {
                    uploadSection.addEventListener(eventName, highlight, false);
                });
                
                ["dragleave", "drop"].forEach(eventName => {
                    uploadSection.addEventListener(eventName, unhighlight, false);
                });
                
                uploadSection.addEventListener("drop", handleDrop, false);
                
                function preventDefaults(e) {
                    e.preventDefault();
                    e.stopPropagation();
                }
                
                function highlight(e) {
                    uploadSection.classList.add("dragover");
                }
                
                function unhighlight(e) {
                    uploadSection.classList.remove("dragover");
                }
                
                function handleDrop(e) {
                    const dt = e.dataTransfer;
                    const files = dt.files;
                    
                    if (files.length > 0) {
                        csvFile.files = files;
                        uploadFile();
                    }
                }
                
                function uploadFile() {
                    const fileInput = document.getElementById("csvFile");
                    const uploadResult = document.getElementById("uploadResult");
                    const uploadBtn = document.getElementById("uploadBtn");
                    
                    if (!fileInput.files[0]) {
                        uploadResult.innerHTML = "<p style=\"color: red;\">Please select a CSV file first</p>";
                        return;
                    }
                    
                    const file = fileInput.files[0];
                    
                    // Validate file type
                    if (!file.name.toLowerCase().match(/\.(csv|txt)$/)) {
                        uploadResult.innerHTML = "<p style=\"color: red;\">Please select a CSV or TXT file</p>";
                        return;
                    }
                    
                    const formData = new FormData();
                    formData.append("csv_file", file);
                    
                    uploadBtn.disabled = true;
                    uploadResult.innerHTML = "<p><span class=\"loading\"></span>Uploading and processing file...</p>";
                    
                    fetch("/api/upload-csv", {
                        method: "POST",
                        body: formData
                    })
                    .then(response => response.json())
                    .then(data => {
                        uploadBtn.disabled = false;
                        
                        if (data.success) {
                            uploadResult.innerHTML = `
                                <div style="color: green; padding: 15px; background: #d4edda; border-radius: 5px; margin-top: 10px;">
                                    <strong>✅ Upload Successful!</strong><br>
                                    📁 File: ${data.filename}<br>
                                    📊 Students: ${data.rows}<br>
                                    📋 Columns: ${data.columns.slice(0, 3).join(", ")}${data.columns.length > 3 ? "..." : ""}<br>
                                    <small>Page will refresh in 2 seconds...</small>
                                </div>
                            `;
                            setTimeout(() => location.reload(), 2000);
                        } else {
                            uploadResult.innerHTML = `
                                <div style="color: red; padding: 15px; background: #f8d7da; border-radius: 5px; margin-top: 10px;">
                                    <strong>❌ Upload Failed:</strong><br>
                                    ${data.error}
                                </div>
                            `;
                        }
                    })
                    .catch(error => {
                        uploadBtn.disabled = false;
                        uploadResult.innerHTML = `
                            <div style="color: red; padding: 15px; background: #f8d7da; border-radius: 5px; margin-top: 10px;">
                                <strong>❌ Upload Error:</strong><br>
                                ${error.message}
                            </div>
                        `;
                    });
                }
                
                function searchStudent() {
                    const seatNumber = document.getElementById("seatNumber");
                    const resultDiv = document.getElementById("result");
                    const searchBtn = document.getElementById("searchBtn");
                    
                    if (!seatNumber) return;
                    
                    const seatValue = seatNumber.value.trim();
                    if (!seatValue) {
                        resultDiv.innerHTML = "<div class=\"result\"><p class=\"error\">❌ Please enter a seat number</p></div>";
                        return;
                    }
                    
                    searchBtn.disabled = true;
                    resultDiv.innerHTML = "<div class=\"result\"><p><span class=\"loading\"></span>Searching for student...</p></div>";
                    
                    fetch("/api/search-student", {
                        method: "POST",
                        headers: {"Content-Type": "application/json"},
                        body: JSON.stringify({seating_no: seatValue})
                    })
                    .then(response => response.json())
                    .then(data => {
                        searchBtn.disabled = false;
                        
                        if (data.error) {
                            resultDiv.innerHTML = `<div class="result"><p class="error">❌ ${data.error}</p></div>`;
                        } else {
                            const statusColor = data.status === "PASS" ? "#28a745" : data.status === "FAIL" ? "#dc3545" : "#6c757d";
                            resultDiv.innerHTML = `
                                <div class="result">
                                    <h3>✅ Student Found!</h3>
                                    <div class="info-row">
                                        <strong>Seat Number:</strong>
                                        <span>${data.seatNumber}</span>
                                    </div>
                                    <div class="info-row">
                                        <strong>Name:</strong>
                                        <span>${data.name}</span>
                                    </div>
                                    <div class="info-row">
                                        <strong>Total Score:</strong>
                                        <span>${data.totalScore}/${data.maxScore}</span>
                                    </div>
                                    <div class="info-row">
                                        <strong>Status:</strong>
                                        <span style="color: ${statusColor}; font-weight: bold;">${data.status}</span>
                                    </div>
                                    <div class="info-row">
                                        <strong>Rank:</strong>
                                        <span>#${data.rank}</span>
                                    </div>
                                    <div style="text-align: center; margin-top: 20px;">
                                        <button onclick="downloadResult(${JSON.stringify(data).replace(/"/g, "&quot;")})">
                                            📄 Download Result Certificate
                                        </button>
                                    </div>
                                </div>
                            `;
                        }
                    })
                    .catch(error => {
                        searchBtn.disabled = false;
                        resultDiv.innerHTML = `<div class="result"><p class="error">❌ Search failed: ${error.message}</p></div>`;
                    });
                }
                
                function downloadResult(studentData) {
                    fetch("/api/download-pdf", {
                        method: "POST",
                        headers: {"Content-Type": "application/json"},
                        body: JSON.stringify({studentData: studentData})
                    })
                    .then(response => {
                        if (response.ok) {
                            return response.blob();
                        }
                        throw new Error("Download failed");
                    })
                    .then(blob => {
                        const url = window.URL.createObjectURL(blob);
                        const a = document.createElement("a");
                        a.href = url;
                        a.download = `STEM_Result_${studentData.seatNumber}.txt`;
                        document.body.appendChild(a);
                        a.click();
                        document.body.removeChild(a);
                        window.URL.revokeObjectURL(url);
                    })
                    .catch(error => {
                        alert("Download failed: " + error.message);
                    });
                }
                
                // Enter key support
                if (document.getElementById("seatNumber")) {
                    document.getElementById("seatNumber").addEventListener("keypress", function(e) {
                        if (e.key === "Enter") {
                            searchStudent();
                        }
                    });
                }
                
                // Auto-focus on seat number input if data is loaded
                ' . ($has_data ? 'document.getElementById("seatNumber")?.focus();' : '') . '
            </script>
        </body>
        </html>';
    }
}

// Initialize the application
$app = new STEMResultsPortal();

// Handle routes
$request_uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$request_method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

switch ($request_uri) {
    case '/':
        echo $app->serveHTML();
        break;
        
    case '/api/upload-csv':
        if ($request_method === 'POST') {
            header('Content-Type: application/json');
            $result = $app->uploadCSVFile($_FILES['csv_file'] ?? []);
            echo json_encode($result, JSON_UNESCAPED_UNICODE);
        } else {
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
        }
        break;
        
    case '/api/search-student':
        if ($request_method === 'POST') {
            header('Content-Type: application/json');
            $input = json_decode(file_get_contents('php://input'), true);
            $seat_number = $input['seating_no'] ?? '';
            
            $result = $app->searchStudent($seat_number);
            
            if (isset($result['error'])) {
                http_response_code(404);
            }
            
            echo json_encode($result, JSON_UNESCAPED_UNICODE);
        } else {
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
        }
        break;
        
    case '/api/download-pdf':
        if ($request_method === 'POST') {
            $input = json_decode(file_get_contents('php://input'), true);
            $student_data = $input['studentData'] ?? [];
            
            if (empty($student_data)) {
                http_response_code(400);
                echo json_encode(['error' => 'Student data required']);
            } else {
                $seat_number = $student_data['seatNumber'] ?? 'unknown';
                $name = $student_data['name'] ?? 'N/A';
                $total_score = $student_data['totalScore'] ?? 'N/A';
                $max_score = $student_data['maxScore'] ?? 500;
                $status = $student_data['status'] ?? 'N/A';
                $rank = $student_data['rank'] ?? 'N/A';
                
                // Generate certificate content
                $content = "STEM G12 OFFICIAL RESULT CERTIFICATE\n";
                $content .= str_repeat("=", 50) . "\n\n";
                $content .= "STUDENT INFORMATION\n";
                $content .= str_repeat("-", 25) . "\n";
                $content .= "Seat Number: " . $seat_number . "\n";
                $content .= "Student Name: " . $name . "\n";
                $content .= "Total Score: " . $total_score . " / " . $max_score . "\n";
                $content .= "Result Status: " . $status . "\n";
                $content .= "Overall Rank: #" . $rank . "\n\n";
                
                $content .= "CERTIFICATE DETAILS\n";
                $content .= str_repeat("-", 25) . "\n";
                $content .= "Generated on: " . date('Y-m-d H:i:s') . "\n";
                $content .= "Academic Year: 2024-2025\n";
                $content .= "Examination: STEM G12 Final Results\n\n";
                
                if ($status === "PASS") {
                    $content .= "🎉 CONGRATULATIONS! 🎉\n";
                    $content .= "The student has successfully passed the examination.\n";
                } elseif ($status === "FAIL") {
                    $content .= "The student did not meet the passing requirements.\n";
                    $content .= "Please contact academic advisor for guidance.\n";
                }
                
                $content .= "\n" . str_repeat("=", 50) . "\n";
                $content .= "STEM Results Portal - Official Document\n";
                $content .= "This certificate is computer generated and valid.\n";
                
                header('Content-Type: application/octet-stream');
                header('Content-Disposition: attachment; filename="STEM_Result_' . $seat_number . '.txt"');
                header('Content-Length: ' . strlen($content));
                header('Cache-Control: no-cache, must-revalidate');
                
                echo $content;
            }
        } else {
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
        }
        break;
        
    case '/api/stats':
        if ($request_method === 'GET') {
            header('Content-Type: application/json');
            echo json_encode($app->getStats(), JSON_UNESCAPED_UNICODE);
        } else {
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
        }
        break;
        
    default:
        http_response_code(404);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Endpoint not found']);
        break;
}
?>
