from flask import Flask, request, send_file, jsonify
import pandas as pd
from io import BytesIO
from reportlab.lib.pagesizes import A4
from reportlab.pdfgen import canvas
import os
import traceback

app = Flask(__name__)

# Global variable to store DataFrame
df = None

def load_csv_data():
    global df
    try:
        print("Loading CSV file...")
        
        # Check if CSV file exists
        if not os.path.exists("results.csv"):
            print("❌ results.csv not found - creating sample data")
            # Create sample data for testing
            sample_data = {
                'seating_no': ['A001', 'A002', 'A003', 'A004', 'A005'],
                'arabic_name': ['أحمد محمد', 'فاطمة علي', 'محمد حسن', 'سارة أحمد', 'علي محمود'],
                'total_score': ['450', '380', '420', '350', '400'],
                'status': ['PASS', 'PASS', 'PASS', 'FAIL', 'PASS']
            }
            df = pd.DataFrame(sample_data)
            print("✅ Sample data created")
            return True
            
        encodings = ['utf-8-sig', 'utf-8', 'cp1256', 'latin-1']
        for encoding in encodings:
            try:
                df = pd.read_csv("results.csv", dtype=str, encoding=encoding)
                print(f"✅ CSV loaded with encoding: {encoding}")
                break
            except Exception as e:
                print(f"Failed with {encoding}: {e}")
                continue
                
        if df is None:
            raise Exception("Could not load CSV with any encoding")
            
        print(f"✅ CSV loaded successfully: {len(df)} rows, {len(df.columns)} columns")
        
        # Clean data
        df.columns = df.columns.str.strip()
        for col in df.columns:
            if df[col].dtype == 'object':
                df[col] = df[col].astype(str).str.strip()
                df[col] = df[col].replace('nan', '').replace('None', '')
                
        print("Available columns:", list(df.columns))
        return True
        
    except Exception as e:
        print(f"❌ Error loading CSV: {e}")
        traceback.print_exc()
        df = pd.DataFrame()
        return False

# Load data on startup
load_csv_data()

@app.route("/")
def index():
    """Serve the HTML file directly instead of using templates"""
    try:
        # Check if HTML file exists
        if os.path.exists('index.html'):
            with open('index.html', 'r', encoding='utf-8') as f:
                return f.read()
        else:
            # Return a simple HTML page if file not found
            return '''
            <!DOCTYPE html>
            <html>
            <head>
                <title>STEM Results Portal</title>
                <style>
                    body { font-family: Arial; text-align: center; margin-top: 100px; }
                    .error { color: red; }
                </style>
            </head>
            <body>
                <h1>STEM Results Portal</h1>
                <p class="error">HTML file not found. Please upload index.html</p>
            </body>
            </html>
            '''
    except Exception as e:
        return f"Error loading page: {str(e)}", 500

@app.route("/debug")
def debug_info():
    """Debug endpoint to check data status"""
    if df is None or df.empty:
        return jsonify({
            "error": "No data loaded",
            "csv_exists": os.path.exists("results.csv"),
            "html_exists": os.path.exists("index.html")
        })
    return jsonify({
        "status": "success",
        "total_rows": len(df),
        "columns": list(df.columns),
        "sample_data": df.head(3).to_dict('records'),
        "files": {
            "csv_exists": os.path.exists("results.csv"),
            "html_exists": os.path.exists("index.html")
        }
    })

@app.route("/api/search-student", methods=["POST"])
def search_student():
    """Search for student by seat number"""
    try:
        if df is None or df.empty:
            return jsonify({"error": "No data available"}), 500
            
        data = request.get_json()
        if not data:
            return jsonify({"error": "No data received"}), 400
            
        seat_number = str(data.get("seating_no", "")).strip()
        if not seat_number:
            return jsonify({"error": "Seat number required"}), 400

        # Find relevant columns
        seat_col = find_column(['seat', 'id', 'seating'])
        name_col = find_column(['name', 'arabic'])
        score_col = find_column(['total', 'score', 'degree'])
        status_col = find_column(['status', 'result', 'pass'])
        
        # Search for student
        student_found = None
        student_index = None
        
        for idx, row in df.iterrows():
            if str(row[seat_col]).strip().upper() == seat_number.upper():
                student_found = row
                student_index = idx
                break
        
        if student_found is None:
            return jsonify({"error": f"Student with seat number '{seat_number}' not found"}), 404
        
        # Calculate rank (position in DataFrame + 1)
        rank = student_index + 1
        
        # Get student name
        student_name = str(student_found[name_col]) if name_col else "Unknown"
        if student_name and student_name not in ["nan", "None", ""]:
            student_name = clean_text(student_name)
        else:
            student_name = "Name not available"
        
        # Get score
        total_score = str(student_found[score_col]) if score_col else "N/A"
        
        # Get status
        status = "UNKNOWN"
        if status_col and str(student_found[status_col]) not in ["nan", "None", ""]:
            status = str(student_found[status_col]).upper()
        elif is_passing_score(total_score):
            status = "PASS"
        else:
            status = "FAIL"
        
        result = {
            "seatNumber": str(student_found[seat_col]) if seat_col else "N/A",
            "seating_no": str(student_found[seat_col]) if seat_col else "N/A",  # For compatibility
            "name": student_name,
            "arabic_name": student_name,  # For compatibility
            "totalScore": total_score,
            "total": total_score,  # For compatibility
            "maxScore": 500,
            "status": status,
            "rank": rank,
            "index": student_index  # For compatibility
        }
        
        return jsonify(result)
        
    except Exception as e:
        print(f"Search error: {e}")
        traceback.print_exc()
        return jsonify({"error": str(e)}), 500

def find_column(keywords):
    """Find column that matches any of the keywords"""
    if df is None:
        return None
        
    for col in df.columns:
        col_lower = col.lower()
        if any(keyword in col_lower for keyword in keywords):
            return col
    
    # Return first column if no match found
    return df.columns[0] if len(df.columns) > 0 else None

def clean_text(text):
    """Clean text for display"""
    return ''.join(char for char in str(text) if char.isprintable() or char.isspace()).strip()

def is_passing_score(score):
    """Check if score is passing"""
    try:
        score_num = float(str(score).replace('%', '').replace(',', ''))
        return score_num >= 250  # Adjust passing score as needed
    except:
        return False

@app.route("/api/download-pdf", methods=["POST"])
def download_pdf():
    """Generate and download PDF report"""
    try:
        data = request.get_json()
        student_data = data.get("studentData", {})
        seat_number = student_data.get("seatNumber", "unknown")
        
        buffer = BytesIO()
        c = canvas.Canvas(buffer, pagesize=A4)
        width, height = A4

        # Use standard fonts only (no Arabic font file needed)
        c.setFont("Helvetica-Bold", 24)
        c.drawCentredString(width/2, height - 80, "STEM G12 Official Results")

        # Student Information
        y = height - 150
        c.setFont("Helvetica-Bold", 16)
        c.drawString(50, y, "Student Information")

        y -= 30
        c.setFont("Helvetica", 12)

        # Student info (simplified - no Arabic support to avoid font issues)
        name = student_data.get('name', 'N/A')
        # Remove Arabic characters if any to prevent PDF errors
        name = ''.join(char for char in name if ord(char) < 256)
        
        info = [
            f"Seat Number: {student_data.get('seatNumber', 'N/A')}",
            f"Name: {name}",
            f"Total Score: {student_data.get('totalScore', 'N/A')}/{student_data.get('maxScore', 500)}",
            f"Status: {student_data.get('status', 'N/A')}",
            f"Rank: #{student_data.get('rank', 'N/A')}"
        ]

        for line in info:
            c.drawString(70, y, line)
            y -= 25

        # Add some decorative elements
        c.setStrokeColorRGB(0, 0.8, 1)
        c.setLineWidth(2)
        c.line(50, height - 100, width - 50, height - 100)
        c.line(50, 80, width - 50, 80)

        # Footer
        c.setFont("Helvetica", 10)
        c.drawCentredString(width/2, 50, f"Generated on: {pd.Timestamp.now().strftime('%Y-%m-%d %H:%M:%S')}")
        c.drawCentredString(width/2, 35, "STEM G12 Results Portal - Official Document")

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
        traceback.print_exc()
        return jsonify({"error": f"PDF generation failed: {str(e)}"}), 500

@app.errorhandler(404)
def not_found(error):
    return jsonify({"error": "Endpoint not found"}), 404

@app.errorhandler(500)
def internal_error(error):
    return jsonify({"error": "Internal server error"}), 500

if __name__ == "__main__":
    print("\n🚀 Starting Flask App")
    print(f"✅ Ready with {len(df) if df is not None else 0} students")
    print("🌐 App will run on Railway assigned port")
    
    # Get port from environment (Railway sets this)
    port = int(os.environ.get('PORT', 5000))
    
    # Run app
    app.run(
        host='0.0.0.0',  # Important for Railway
        port=port,
        debug=False  # Set to False for production
    )
