require('dotenv').config();
const express = require('express');
const cors = require('cors');
const { Pool } = require('pg');
const bcrypt = require('bcrypt');
const jwt = require('jsonwebtoken');

const app = express();
app.use(cors());
app.use(express.json());

// Connect to your PostgreSQL users database
const pool = new Pool({
    connectionString: process.env.DATABASE_URL,
});

const JWT_SECRET = process.env.JWT_SECRET || 'super_secret_conference_key_2026';

// ==========================================
// SECURITY MIDDLEWARE: Check the VIP Wristband (JWT)
// ==========================================
const authenticateToken = (req, res, next) => {
    // 1. Look for the Authorization header sent by Flutter
    const authHeader = req.headers['authorization'];
    const token = authHeader && authHeader.split(' ')[1]; // Format is "Bearer <token>"

    if (!token) {
        return res.status(401).json({ error: 'Access denied. No token provided.' });
    }

    // 2. Verify the token uses our secret key
    jwt.verify(token, JWT_SECRET, (err, user) => {
        if (err) return res.status(403).json({ error: 'Invalid or expired token.' });
        
        // 3. Attach the decrypted user data (which contains userId) to the request!
        req.user = user; 
        next(); // Let them pass to the database route
    });
};



// ==========================================
// 0. HEALTH CHECK (To test Wi-Fi connection)
// ==========================================
app.get('/api/test', (req, res) => {
    console.log("⚡ Flutter App Connected!");
    res.json({ message: "Backend is online!" });
});

// ==========================================
// 1. REGISTER (SIGN UP) ENDPOINT
// ==========================================
app.post('/api/register', async (req, res) => {
    // 1. Extract using 'phone' instead of 'phone_no'
    const { 
        full_name, 
        email, 
        password, 
        confirm_password, 
        phone, 
        organization, 
        designation, 
        country 
    } = req.body;

    try {
        if (password !== confirm_password) {
            return res.status(400).json({ error: 'Passwords do not match!' });
        }

        const userCheck = await pool.query('SELECT * FROM users WHERE email = $1', [email]);
        if (userCheck.rows.length > 0) {
            return res.status(400).json({ error: 'User already exists with this email' });
        }

        const salt = await bcrypt.genSalt(10);
        const passwordHash = await bcrypt.hash(password, salt);

        // 2. Insert using 'phone' to match your database column perfectly
        const newUser = await pool.query(
            `INSERT INTO users (email, password_hash, full_name, phone, organization, designation, country) 
             VALUES ($1, $2, $3, $4, $5, $6, $7) RETURNING id, email, full_name`,
            [email, passwordHash, full_name, phone, organization, designation, country]
        );

        const token = jwt.sign({ userId: newUser.rows[0].id }, JWT_SECRET, { expiresIn: '7d' });
        
        console.log(`✅ New user registered with full details: ${email}`);
        res.status(201).json({ token, user: newUser.rows[0] });

    } catch (err) {
        console.error("Backend Error:", err.message);
        res.status(500).json({ error: 'Registration failed' });
    }
});

// ==========================================
// 2. LOGIN ENDPOINT
// ==========================================
app.post('/api/login', async (req, res) => {
    const { email, password } = req.body;
    try {
        console.log(`🔐 Login attempt for: ${email}`);
        
        const userResult = await pool.query('SELECT * FROM users WHERE email = $1', [email]);
        if (userResult.rows.length === 0) {
            return res.status(401).json({ error: 'Invalid email or password' });
        }

        const user = userResult.rows[0];
        const isMatch = await bcrypt.compare(password, user.password_hash);
        
        if (!isMatch) {
            return res.status(401).json({ error: 'Invalid email or password' });
        }

        const token = jwt.sign({ userId: user.id }, JWT_SECRET, { expiresIn: '7d' });
        delete user.password_hash; 
        
        console.log(`✅ Login successful for: ${email}`);
        res.json({ token, user });
    } catch (err) {
        res.status(500).json({ error: 'Login failed' });
    }
});

// ==========================================
// 3. GET LOGGED-IN USER DETAILS (For the Pass Screen)
// ==========================================
app.get('/api/user/me', authenticateToken, async (req, res) => {
    try {
        // Because of the middleware, we now know exactly who this is!
        // req.user.userId was securely decoded from their token.
        const userId = req.user.userId;

        console.log(`🎫 Fetching digital pass details for User ID: ${userId}`);

        // Fetch ONLY this user from the database. 
        // We explicitly list columns so we NEVER accidentally send the password_hash!
        const userResult = await pool.query(
            `SELECT id, email, full_name, phone, organization, designation, country, role 
             FROM users WHERE id = $1`,
            [userId]
        );

        if (userResult.rows.length === 0) {
            return res.status(404).json({ error: 'User not found in database.' });
        }

        // Send the secure user profile back to Flutter
        res.json(userResult.rows[0]);

    } catch (err) {
        console.error("Pass Screen Error:", err.message);
        res.status(500).json({ error: 'Failed to fetch user details' });
    }
});



const PORT = process.env.PORT || 3000;
app.listen(PORT, () => console.log(`🚀 API running on port ${PORT}`));