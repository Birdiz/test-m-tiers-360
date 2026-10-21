# Les données sales sont importées et signalées, jamais rejetées

Toute inscription est importée, même si un champ est inexploitable (e-mail `pas d'adresse`, date
`12/03/2008`, naissance en 1900 ou 2031, type de structure inconnu). Le champ normalisé reste vide et une
anomalie nomme le champ, la raison et la valeur brute fautive. Rejeter une inscription cacherait à l'équipe
opérations une personne qui viendra le jour J. On ne garde pas la réponse brute complète : seule la valeur
fautive est conservée, pour limiter la copie de données personnelles, en majorité celles de mineurs.
