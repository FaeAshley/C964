import streamlit as st
from sqlalchemy import create_engine
import pandas as pd
import matplotlib.pyplot as plt
import seaborn as sns
from sklearn.model_selection import train_test_split
from sklearn.ensemble import RandomForestClassifier
from sklearn.metrics import classification_report, accuracy_score, confusion_matrix
from sklearn.preprocessing import LabelEncoder

# Step 1: Connect to the Database
engine = create_engine('mysql+mysqlconnector://root:@localhost:3307/pinball_api')

# Fetch all data in a single query
st.title("Pinball Game Analysis Dashboard")

query = """
SELECT 
    p.ifpa_rank,
    g.opdb_name,
    mp.player_id,
    g.first AS first_place,
    g.second AS second_place,
    g.third AS third_place,
    g.fourth AS fourth_place
FROM 
    games g
JOIN 
    matchplay_players mp ON g.first = mp.player_id OR g.second = mp.player_id OR g.third = mp.player_id OR g.fourth = mp.player_id
JOIN 
    players p ON mp.ifpa_id = p.ifpa_id
LIMIT 10000
"""
# Execute the query and load data into DataFrame
df = pd.read_sql(query, engine)

# Step 2: Prepare the Data
for col in ['ifpa_rank', 'first_place', 'second_place', 'third_place', 'fourth_place']:
    df[col] = pd.to_numeric(df[col], errors='coerce')
df.dropna(inplace=True)

# Descriptive statistics
st.write("Descriptive Statistics:")
st.write(df.describe())

# Group by game and calculate average placement
game_summary = df.groupby('opdb_name').agg({
    'ifpa_rank': ['mean', 'std'],
    'first_place': 'mean',
    'second_place': 'mean',
    'third_place': 'mean',
    'fourth_place': 'mean'
}).reset_index()

# Flatten the MultiIndex columns
game_summary.columns = ['_'.join(col).strip() if type(col) is tuple else col for col in game_summary.columns]

# Add user input for selecting a specific game
selected_game = st.selectbox('Select a Game:', df['opdb_name'].unique())
filtered_data = df[df['opdb_name'] == selected_game]
st.write(f"Filtered Data for {selected_game}:")
st.write(filtered_data)

# Visualization 1: Histogram of IFPA Rank
st.header('Distribution of Player Rankings (IFPA Rank)')
fig, ax = plt.subplots()
sns.histplot(filtered_data['ifpa_rank'], bins=30, kde=True, ax=ax)
st.pyplot(fig)

# Interactive bar plot for top/bottom games
rank_filter = st.slider('Select Average Rank Threshold:', int(df['ifpa_rank'].min()), int(df['ifpa_rank'].max()), 2000)
top_games = game_summary[game_summary['first_place_mean'] <= rank_filter].sort_values('first_place_mean', ascending=False).head(10)
fig, ax = plt.subplots(figsize=(12, 8))
sns.barplot(x='first_place_mean', y='opdb_name_', data=top_games, errorbar=None, ax=ax)
st.pyplot(fig)

# Visualization 3: Heatmap of Correlations
st.header('Correlation Heatmap')
fig, ax = plt.subplots(figsize=(10, 8))
correlation_matrix = df[['ifpa_rank', 'first_place', 'second_place', 'third_place', 'fourth_place']].corr()
sns.heatmap(correlation_matrix, annot=True, cmap='coolwarm', ax=ax)
st.pyplot(fig)

# Encode `game_name` as a numeric feature
label_encoder = LabelEncoder()
df['game_name_encoded'] = label_encoder.fit_transform(df['opdb_name'])

# Feature set: selecting features for the model
X = df[['ifpa_rank', 'game_name_encoded', 'first_place', 'second_place', 'third_place', 'fourth_place']]

# Define labels (0 for skill-based, 1 for luck-based)
threshold = X[['first_place', 'second_place', 'third_place', 'fourth_place']].mean(axis=1).mean()  # Example threshold
df['label'] = (X[['first_place', 'second_place', 'third_place', 'fourth_place']].mean(axis=1) > threshold).astype(int)

# Label set
y = df['label']

# Split the data
X_train, X_test, y_train, y_test = train_test_split(X, y, test_size=0.2, random_state=42)

st.write("Data split into training and testing sets.")

# Initialize Random Forest Classifier
model = RandomForestClassifier(n_estimators=100, random_state=42)

# Train the model
model.fit(X_train, y_train)
st.write("Model training completed.")

# Make predictions
y_pred = model.predict(X_test)

# Evaluate the model
st.subheader("Model Evaluation")
st.write("Accuracy:", accuracy_score(y_test, y_pred))

st.write("Classification Report:", classification_report(y_test, y_pred, output_dict=True))
st.write("Confusion Matrix:\n", confusion_matrix(y_test, y_pred))

# Feature importance
st.header('Feature Importance')
feature_importances = pd.Series(model.feature_importances_, index=X_train.columns)
fig, ax = plt.subplots(figsize=(10, 6))
feature_importances.sort_values().plot(kind='barh', ax=ax)
st.pyplot(fig)

# User input to run a prediction using the model
st.header("Predict Game Type (Skill-based or Luck-based)")
player_rank = st.number_input("Enter Player Rank:", min_value=int(df['ifpa_rank'].min()), max_value=int(df['ifpa_rank'].max()), value=1000)
game_encoded = label_encoder.transform([selected_game])[0]
input_data = [[player_rank, game_encoded, player_rank, player_rank, player_rank, player_rank]]  # Example input

# Predict using the Random Forest model
prediction = model.predict(input_data)
prediction_label = 'Luck-based' if prediction[0] == 1 else 'Skill-based'
st.write(f"Prediction for {selected_game} with Player Rank {player_rank}: {prediction_label}")

# Interactive histogram based on user-selected data
st.header('Distribution of Player Rankings (IFPA Rank)')
fig, ax = plt.subplots()
sns.histplot(filtered_data['ifpa_rank'], bins=30, kde=True, ax=ax)
st.pyplot(fig)

# Interactive bar plot for top/bottom games
rank_filter = st.slider('Select Average Rank Threshold:', int(df['ifpa_rank'].min()), int(df['ifpa_rank'].max()), 2000, key='rank_threshold_slider')
top_games = game_summary[game_summary['first_place_mean'] <= rank_filter].sort_values('first_place_mean', ascending=False).head(10)
fig, ax = plt.subplots(figsize=(12, 8))
sns.barplot(x='first_place_mean', y='opdb_name_', data=top_games, errorbar=None, ax=ax)
st.pyplot(fig)

# User input to run a prediction using the model
st.header("Predict Game Type (Skill-based or Luck-based)")
player_rank = st.number_input("Enter Player Rank:", min_value=int(df['ifpa_rank'].min()), max_value=int(df['ifpa_rank'].max()), value=1000)
game_encoded = label_encoder.transform([selected_game])[0]
input_data = [[player_rank, game_encoded, player_rank, player_rank, player_rank, player_rank]]  # Example input

# Predict using the Random Forest model
prediction = model.predict(input_data)
prediction_label = 'Luck-based' if prediction[0] == 1 else 'Skill-based'
st.write(f"Prediction for {selected_game} with Player Rank {player_rank}: {prediction_label}")
