from flask import Flask, render_template, request, send_file, jsonify
import pandas as pd
from io import BytesIO
from reportlab.lib.pagesizes import A4
from reportlab.pdfgen import canvas
import os

app = Flask(__name__)

# Global variable to store DataFrame
df = None

def load_csv_data():
    global df
    try:
        print("Loading CSV file...")
        # Try different encodings for Arabic text
        encodings = ['utf-8-sig', 'utf-8', 'cp1256', 'latin-1']
        
        for encoding in encodings:
            try:
                df = pd.read_csv(r"D:\stem-results-portal\results.csv", dtype=str, encoding=encoding)
                print(f"✅ CSV loaded with encoding: {encoding}")
                break
            except:
                continue
        
        if df is None:
            raise Exception("Could not load CSV with any encoding")
        
        print(f"✅ CSV loaded successfully: {len(df)} rows, {len(df.columns)} columns")
        
        # Clean column names
        df.columns = df.columns.str.strip()
        
        # Clean data and handle special characters
        for col in df.columns:
            if df[col].dtype == 'object':
                df[col] = df[col].astype(str).str.strip()
                # Replace any problematic characters that might show as black bars
                df[col] = df[col].replace('nan', '').replace('None', '')
        
        print("Available columns:", list(df.columns))
        
        # Show sample names to check encoding
        name_col = None
        for col in df.columns:
            col_lower = col.lower()
            if any(x in col_lower for x in ['name', 'arabic']):
                name_col = col
                break
        
        if name_col:
            print(f"Sample names from {name_col}:")
            sample_names = df[name_col].head(3).tolist()
            for i, name in enumerate(sample_names):
                print(f"  {i+1}: '{name}' (length: {len(str(name))})")
        
        return True
    except Exception as e:
        print(f"❌ Error loading CSV: {e}")
        df = pd.DataFrame()
        return False

# Load CSV on startup
load_csv_data()

@app.route("/")
def index():
    return render_template("index.html")

@app.route("/debug")
def debug_info():
    if df is None or df.empty:
        return jsonify({"error": "No data loaded"})
    
    return jsonify({
        "total_rows": len(df),
        "columns": list(df.columns),
        "sample_data": df.head(3).to_dict('records')
    })

@app.route("/api/search-student", methods=["POST"])
def search_student():
    try:
        print("\n=== STUDENT SEARCH ===")
        
        # Basic checks
        if df is None or df.empty:
            return jsonify({"error": "No data available"}), 500
        
        # Get request data
        data = request.get_json()
        if not data:
            return jsonify({"error": "No data received"}), 400
        
        seat_number = str(data.get("seating_no", "")).strip()
        if not seat_number:
            return jsonify({"error": "Seat number required"}), 400
        
        print(f"Searching for: '{seat_number}'")
        print(f"DataFrame shape: {df.shape}")
        
        # Find columns
        seat_col = None
        name_col = None
        score_col = None
        
        # Find seat column
        for col in df.columns:
            col_lower = col.lower()
            if any(x in col_lower for x in ['seat', 'id', 'seating']):
                seat_col = col
                break
        
        if not seat_col:
            seat_col = df.columns[0]
        
        # Find name column
        for col in df.columns:
            col_lower = col.lower()
            if any(x in col_lower for x in ['name', 'arabic']):
                name_col = col
                break
        
        # Find score column
        for col in df.columns:
            col_lower = col.lower()
            if any(x in col_lower for x in ['total', 'score', 'degree']):
                score_col = col
                break
        
        print(f"Using columns - Seat: {seat_col}, Name: {name_col}, Score: {score_col}")
        
        # Search for student
        student_found = None
        student_index = None
        
        # Try exact match first
        for idx, row in df.iterrows():
            if str(row[seat_col]).strip().upper() == seat_number.upper():
                student_found = row
                student_index = idx
                break
        
        if student_found is None:
            return jsonify({"error": "Student not found"}), 404
        
        # Calculate rank (row position in CSV)
        rank = student_index + 1  # Convert 0-based index to 1-based rank
        
        print(f"Found student at index {student_index}, rank: {rank}")
        
        # Prepare result
        student_name = str(student_found[name_col]) if name_col else "Unknown"
        
        # Clean the name for display (remove any problematic characters)
        if student_name and student_name != "nan" and student_name != "None":
            # Remove any non-printable characters that might show as black bars
            student_name = ''.join(char for char in student_name if char.isprintable() or char.isspace())
            student_name = student_name.strip()
        else:
            student_name = "Name not available"
        
        result = {
            "seatNumber": str(student_found[seat_col]) if seat_col else "N/A",
            "name": student_name,
            "totalScore": str(student_found[score_col]) if score_col else "N/A",
            "maxScore": 500,
            "status": "PASS" if score_col and is_passing_score(student_found[score_col]) else "FAIL",
            "rank": rank
        }
        
        print(f"Result: {result}")
        return jsonify(result)
        
    except Exception as e:
        print(f"Search error: {e}")
        import traceback
        traceback.print_exc()
        return jsonify({"error": str(e)}), 500

def is_passing_score(score):
    try:
        score_num = float(str(score).replace('%', '').replace(',', ''))
        return score_num >= 250
    except:
        return False

@app.route("/api/download-pdf", methods=["POST"])
def download_pdf():
    try:
        data = request.get_json()
        student_data = data.get("studentData", {})
        seat_number = student_data.get("seatNumber", "unknown")
        
        buffer = BytesIO()
        c = canvas.Canvas(buffer, pagesize=A4)
        width, height = A4
        
        # Header
        c.setFont("Helvetica-Bold", 24)
        c.drawCentredString(width/2, height - 80, "STEM G12 Official Results")
        
        # Student Information
        y = height - 150
        c.setFont("Helvetica-Bold", 16)
        c.drawString(50, y, "Student Information")
        
        y -= 30
        c.setFont("Helvetica", 12)
        
        info = [
            f"Seat Number: {student_data.get('seatNumber', 'N/A')}",
            f"Name: {student_data.get('name', 'N/A')}",
            f"Total Score: {student_data.get('totalScore', 'N/A')}/{student_data.get('maxScore', 500)}",
            f"Status: {student_data.get('status', 'N/A')}",
            f"Rank: #{student_data.get('rank', 'N/A')}"
        ]
        
        for line in info:
            c.drawString(70, y, line)
            y -= 25
        
        # Footer
        c.setFont("Helvetica-Oblique", 10)
        c.drawCentredString(width/2, 50, f"Generated on: {pd.Timestamp.now().strftime('%Y-%m-%d %H:%M:%S')}")
        
        c.save()
        buffer.seek(0)
        
        return send_file(
            buffer,
            as_attachment=True,
            download_name=f"STEM_Result_{seat_number}.pdf",
            mimetype="application/pdf"
        )
        
    except Exception as e:
        print(f"PDF error: {e}")
        return jsonify({"error": "PDF generation failed"}), 500

if __name__ == "__main__":
    print("\n🚀 Starting Flask App")
    if df is not None and not df.empty:
        print(f"✅ Ready with {len(df)} students")
    else:
        print("❌ No data loaded")
    
    print("🌐 App running at: http://localhost:5000")
    app.run(debug=True, port=5000)